<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Custom\Tools\Accounting\Cfg;
use Espo\Custom\Tools\Accounting\Connectors\Registry;
use Espo\Custom\Tools\Accounting\SyncService;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Licensing\Modules;
use stdClass;

/**
 * AccountingSync/action/*  (plain reads of the ledger go through the entity API; it is read-only, see Hooks/AccountingSync/Immutable)
 *
 *   adminGet / adminSave / adminTest      administrator only (the settings page; not gated by the module flag)
 *   status                                the queue in numbers      } the Revenue reports switch (+ read: all on orders)
 *   summary { dateFrom, dateTo }          counts, failed, missing, drift   } AND read on AccountingSync
 *   enqueue { dateFrom, dateTo }          queue the completed orders of those days  } the same + EDIT on AccountingSync,
 *   run                                   send what is due, now                      } and the module flag
 *   resend { id } | { all: true }         "Send again" for Failed / Skipped rows (a Sent row is refused: 409)
 *
 * A tablet's device key opens nothing here. Secrets are write-only: a blank field keeps the stored value, nothing secret
 * is ever returned or logged.
 */
class AccountingSync extends \Espo\Core\Templates\Controllers\Base
{
    /** key => [type, secret?]  Everything the settings page may write. */
    private const KEYS = [
        'accountingProvider' => ['provider'], 'accountingMode' => ['mode'], 'accountingStartDate' => ['date'], 'accountingBatchTime' => ['time'],
        'accountingRetailName' => ['str'], 'accountingItemCodeBase' => ['str'], 'accountingItemCodeAddon' => ['str'],
        'accountingDueDays' => ['int'], 'accountingNumberPrefix' => ['str'],
        'accountingPayMethodCash' => ['str'], 'accountingPayMethodCard' => ['str'],
        'accountingPayAccountCash' => ['str'], 'accountingPayAccountCard' => ['str'],
        'accountingVatId21' => ['str'], 'accountingVatId0' => ['str'],
        'accountingMeritHost' => ['host'], 'accountingMeritIban' => ['str'],
        'accountingMeritId' => ['str', true], 'accountingMeritKey' => ['str', true],
        'accountingSmartId' => ['str', true], 'accountingSmartKey' => ['str', true],
    ];

    /** A change of any of these invalidates the cached ids (general customer, VAT ids, ensured articles). */
    private const CACHE_BREAKERS = [
        'accountingProvider', 'accountingMeritHost', 'accountingMeritId', 'accountingMeritKey', 'accountingSmartId', 'accountingSmartKey',
        'accountingVatId21', 'accountingVatId0', 'accountingRetailName',
    ];

    private function guard(): void
    {
        $this->injectableFactory->create(DeviceGuard::class)->denyDevice($this->user);
    }

    private function admin(): void
    {
        $this->guard();

        if (!$this->user->isAdmin()) {
            throw new Forbidden('admin_only');
        }
    }

    /** Revenue figures leave the building in this feature: the Revenue reports switch + read: all on orders, like the export. */
    private function staff(string $action): void
    {
        $this->guard();

        if (!$this->user->isAdmin()) {
            if ($this->acl->getLevel('WashOrder', 'read') !== 'all' || !$this->acl->checkScope('RevenueReports') || !$this->acl->checkScope('AccountingSync', $action)) {
                throw new Forbidden('accounting_forbidden');
            }
        }

        if (!$this->injectableFactory->create(Modules::class)->moduleEnabled(Modules::ACCOUNTING)) {
            throw new Forbidden('module_disabled');
        }
    }

    private function sync(): SyncService
    {
        return $this->injectableFactory->create(SyncService::class);
    }

    private function cfg(): Cfg
    {
        return $this->injectableFactory->create(Cfg::class);
    }

    private function period(Request $request): array
    {
        $d = $request->getParsedBody();
        $from = trim((string) ($d->dateFrom ?? date('Y-m-01')));
        $to = trim((string) ($d->dateTo ?? date('Y-m-d')));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw new BadRequest('bad_period');
        }

        return [$from, $to];
    }

    // ------------------------------------------------------------------ settings (administrator)

    public function postActionAdminGet(): stdClass
    {
        $this->admin();
        $config = $this->config;
        $values = [];
        $secrets = [];

        foreach (self::KEYS as $k => $def) {
            if (!empty($def[1])) {
                $secrets[$k] = trim((string) $config->get($k, '')) !== '';
            } else {
                $values[$k] = $config->get($k, null);
            }
        }

        $reg = $this->injectableFactory->create(Registry::class);
        $connectors = [];

        foreach ($reg->all() as $c) {
            $connectors[] = (object) ['code' => $c->code(), 'label' => $c->label(), 'configured' => $c->isConfigured()];
        }

        $svc = $this->sync();

        return (object) [
            'values' => (object) $values,
            'secrets' => (object) $secrets,
            'connectors' => $connectors,
            'modules' => ['accounting' => $this->injectableFactory->create(Modules::class)->moduleEnabled(Modules::ACCOUNTING)],
            'enabled' => $svc->enabled(),
            'testMode' => $config->get('accountingTestMode') === true,
            'timeZone' => (string) $config->get('timeZone', 'UTC'),
        ];
    }

    public function postActionAdminSave(Request $request): stdClass
    {
        $this->admin();
        $writer = $this->injectableFactory->create(ConfigWriter::class);
        $d = $request->getParsedBody();
        $set = [];

        foreach ((array) ($d->values ?? []) as $k => $v) {
            $def = self::KEYS[$k] ?? null;

            if (!$def) {
                continue;
            }

            if (!empty($def[1]) && ($v === '' || $v === null)) {
                continue; // a blank secret field keeps the stored one
            }

            $set[$k] = match ($def[0]) {
                'provider' => in_array((string) $v, ['off', 'merit', 'smartaccounts'], true) ? ($v === 'off' ? '' : (string) $v) : throw new BadRequest('bad_provider'),
                'mode' => in_array((string) $v, ['manual', 'auto', 'batch'], true) ? (string) $v : throw new BadRequest('bad_mode'),
                'host' => in_array((string) $v, ['ee', 'pl'], true) ? (string) $v : 'ee',
                'date' => ($v === '' || $v === null) ? '' : (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : throw new BadRequest('bad_date')),
                'time' => ($v === '' || $v === null) ? '23:00' : (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : throw new BadRequest('bad_time')),
                'int' => $v === '' || $v === null ? null : max(0, min(365, (int) $v)),
                default => mb_substr(trim((string) $v), 0, 200),
            };
        }

        foreach ((array) ($d->clear ?? []) as $k) {
            if (!empty(self::KEYS[$k][1])) {
                $set[$k] = '';
            }
        }

        if ($set) {
            $writer->setMultiple($set);

            if (array_intersect(array_keys($set), self::CACHE_BREAKERS)) {
                $writer->set('accountingCache', []);
            }

            $writer->save();
        }

        return (object) ['ok' => true, 'saved' => array_keys($set)];
    }

    public function postActionAdminTest(): stdClass
    {
        $this->admin();
        $c = $this->injectableFactory->create(Registry::class)->active();

        if (!$c) {
            return (object) ['ok' => false, 'message' => 'no_provider'];
        }

        $r = $c->testConnection();

        return (object) ['ok' => (bool) $r['ok'], 'message' => (string) $r['message'], 'provider' => $c->code(), 'details' => (object) ($r['details'] ?? [])];
    }

    // ------------------------------------------------------------------ the queue (staff)

    public function postActionStatus(): stdClass
    {
        $this->staff('read');
        $c = $this->injectableFactory->create(Registry::class)->active();
        $counts = $this->sync()->counts();

        return (object) [
            'provider' => $c ? $c->code() : '',
            'configured' => $c ? $c->isConfigured() : false,
            'mode' => $this->cfg()->mode(),
            'counts' => (object) $counts,
        ];
    }

    public function postActionSummary(Request $request): stdClass
    {
        $this->staff('read');
        [$from, $to] = $this->period($request);

        return (object) $this->toObject($this->sync()->summary($from, $to));
    }

    public function postActionEnqueue(Request $request): stdClass
    {
        $this->staff('edit');
        [$from, $to] = $this->period($request);

        if (!$this->sync()->enabled()) {
            throw new BadRequest('not_configured');
        }

        $d = $request->getParsedBody();
        $only = isset($d->orderId) ? trim((string) $d->orderId) : null;

        if ($only !== null && !preg_match('/^[A-Za-z0-9]{10,24}$/', $only)) {
            throw new BadRequest('bad_order');
        }

        return (object) ['queued' => $this->sync()->enqueueRange($from, $to, 500, $only)];
    }

    public function postActionRun(): stdClass
    {
        $this->staff('edit');

        if (!$this->sync()->enabled()) {
            throw new BadRequest('not_configured');
        }

        return (object) $this->sync()->run(30, true);
    }

    public function postActionResend(Request $request): stdClass
    {
        $this->staff('edit');
        $d = $request->getParsedBody();

        if (!empty($d->all)) {
            return (object) ['requeued' => $this->sync()->resendFailed()];
        }

        $id = trim((string) ($d->id ?? ''));

        if ($id === '') {
            throw new BadRequest('id_required');
        }

        $status = $this->sync()->statusOf($id);

        if ($status === null) {
            throw new \Espo\Core\Exceptions\NotFound();
        }

        if ($status === 'Sent') {
            throw new \Espo\Core\Exceptions\Conflict('already_sent'); // never a second sale
        }

        return (object) ['requeued' => $this->sync()->resend($id) ? 1 : 0];
    }

    /** @return array<string, mixed> arrays that are lists stay lists, maps become objects (empty map -> {}) */
    private function toObject(array $a): array
    {
        return (array) json_decode((string) json_encode($a), false);
    }
}
