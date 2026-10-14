<?php

namespace Espo\Custom\Tools\Accounting;

use Espo\Custom\Tools\Accounting\Connectors\Connector;
use Espo\Custom\Tools\Accounting\Connectors\Registry;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The queue between the orders and the accounting program.
 *
 *   one ledger row per (order, program, kind) - a UNIQUE index, so a second send of the same order cannot even be written;
 *   a row is claimed with an atomic UPDATE (lockedUntil) so two workers (the cron job and a person pressing the button) never
 *   send the same row at once; and before every create the connector asks the program whether that document number is already
 *   booked (a crash after a successful call is therefore healed by ADOPTING the booked document, never by a duplicate);
 *   nothing here ever throws into an order save: the hook only writes a Pending row.
 *
 * Status: Pending (waiting) -> Sent | Failed (retry later, exponential, or fix the setup) | Skipped (nothing to book: zero total,
 * not a completed order, order gone). A Sent row is final: the "send again" button refuses it (a correction is a credit
 * invoice made in the accounting program, not a second sale).
 */
class SyncService
{
    private const OPT = ['allowLedgerWrite' => true, 'skipNotify' => true];

    public function __construct(
        private EntityManager $em,
        private Cfg $cfg,
        private Registry $registry,
        private SaleBuilder $builder,
        private Modules $modules
    ) {}

    // ------------------------------------------------------------------ enqueue

    public function enabled(): bool
    {
        return $this->modules->moduleEnabled(Modules::ACCOUNTING) && $this->registry->active() !== null && $this->registry->active()->isConfigured();
    }

    private function startDate(): ?string
    {
        $d = $this->cfg->str('accountingStartDate');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /** Create the Pending row of one order (idempotent). Returns the row, or null when there is nothing to queue. */
    public function enqueue(Entity $order, string $connector, string $kind = 'Sale', array $extra = []): ?Entity
    {
        $repo = $this->em->getRDBRepository('AccountingSync');
        $row = $repo->where(['washOrderId' => $order->getId(), 'connector' => $connector, 'kind' => $kind, 'deleted' => false])->findOne();

        if ($row) {
            return $row;
        }

        $e = $this->em->getNewEntity('AccountingSync');
        $e->set(array_merge([
            'name' => (string) $order->get('name') . ' > ' . $connector . ($kind === 'Payment' ? ' (payment)' : ''),
            'washOrderId' => $order->getId(),
            'connector' => $connector,
            'kind' => $kind,
            'status' => 'Pending',
            'attempts' => 0,
        ], $extra));

        try {
            $this->em->saveEntity($e, self::OPT);
        } catch (\Throwable $t) {
            // lost a race with another writer: the unique index did its job
            return $repo->where(['washOrderId' => $order->getId(), 'connector' => $connector, 'kind' => $kind, 'deleted' => false])->findOne();
        }

        return $e;
    }

    /** Called from the order hook (afterSave): only decides and writes a Pending row, never calls the network. */
    public function onOrderSaved(Entity $order): void
    {
        if (!$this->enabled() || $this->cfg->mode() === 'manual') {
            return;
        }

        $c = $this->registry->active();

        if ($order->get('status') !== 'Completed' || !$this->afterStart($order)) {
            return;
        }

        $sale = $this->enqueue($order, $c->code());

        // paid AFTER the sale had been booked as unpaid: queue the payment (cash / card only)
        if ($sale && $sale->get('status') === 'Sent' && !$sale->get('paidIncluded') && $order->get('paymentStatus') === 'Paid'
            && in_array((string) $order->get('paymentMethod'), ['Cash', 'Card'], true)) {
            $this->enqueue($order, $c->code(), 'Payment');
        }
    }

    private function afterStart(Entity $order): bool
    {
        $start = $this->startDate();

        if ($start === null) {
            return false; // no first-export date chosen: nothing goes out by itself
        }

        $at = (string) ($order->get('orderDateTime') ?: $order->get('finishedAt'));

        return $at !== '' && $at >= $this->localDayStartUtc($start);
    }

    private function localDayStartUtc(string $day): string
    {
        return (new \DateTime($day . ' 00:00:00', $this->cfg->timeZone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Queue every Completed order of the days $from..$to (local) that has no row yet. Bounded.
     * @return int rows created
     */
    /** @param string|null $onlyOrderId queue just that one completed order (the same rules as the period) */
    public function enqueueRange(string $from, string $to, int $cap = 500, ?string $onlyOrderId = null): int
    {
        $c = $this->registry->active();

        if (!$c) {
            return 0;
        }

        $utc = new \DateTimeZone('UTC');
        $fromU = (new \DateTime($from . ' 00:00:00', $this->cfg->timeZone()))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toU = (new \DateTime($to . ' 23:59:59', $this->cfg->timeZone()))->setTimezone($utc)->format('Y-m-d H:i:s');
        $orders = $this->em->getRDBRepository('WashOrder')
            ->where(array_merge(['status' => 'Completed', 'orderDateTime>=' => $fromU, 'orderDateTime<=' => $toU, 'deleted' => false],
                $onlyOrderId !== null ? ['id' => $onlyOrderId] : []))
            ->order('orderDateTime', 'ASC')->limit(0, $cap * 3)->find();
        $n = 0;

        foreach ($orders as $o) {
            if ($n >= $cap) {
                break;
            }

            if ($this->em->getRDBRepository('AccountingSync')->where(['washOrderId' => $o->getId(), 'connector' => $c->code(), 'kind' => 'Sale', 'deleted' => false])->count() > 0) {
                continue;
            }

            if ($this->enqueue($o, $c->code())) {
                $n++;
            }
        }

        return $n;
    }

    /** The job's safety net: orders completed while the hook was off (module / settings changed, import). Only in auto / batch mode. */
    public function backfill(int $cap = 100): int
    {
        $start = $this->startDate();

        if (!$this->enabled() || $this->cfg->mode() === 'manual' || $start === null) {
            return 0;
        }

        return $this->enqueueRange($start, date('Y-m-d', time() + 86400), $cap);
    }

    // ------------------------------------------------------------------ run

    /** Batch mode sends only after the chosen local time of day. */
    private function batchOpen(): bool
    {
        if ($this->cfg->mode() !== 'batch') {
            return true;
        }

        $t = $this->cfg->str('accountingBatchTime', '23:00');

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
            $t = '23:00';
        }

        return (new \DateTime('now', $this->cfg->timeZone()))->format('H:i') >= $t;
    }

    /**
     * Send what is due. $force = a person pressed "run now" (ignores the batch window and the mode, not the module flag).
     * @return array{sent: int, failed: int, skipped: int, pending: int, stopped: bool}
     */
    public function run(int $limit = 30, bool $force = false): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0, 'stopped' => false];

        if (!$this->enabled() || (!$force && !$this->batchOpen())) {
            return $out;
        }

        $c = $this->registry->active();
        $now = gmdate('Y-m-d H:i:s');
        $rows = $this->em->getRDBRepository('AccountingSync')
            ->where([
                'connector' => $c->code(),
                'deleted' => false,
                'status' => ['Pending', 'Failed'],
                'OR' => [['nextAttemptAt' => null, 'status' => 'Pending'], ['nextAttemptAt<=' => $now]],
            ])
            ->order('createdAt', 'ASC')->limit(0, $limit)->find();

        foreach ($rows as $row) {
            $r = $this->attempt($row, $c);
            $out[$r['result']]++;

            if ($r['stop']) {
                $out['stopped'] = true;
                break;
            }
        }

        $out['pending'] = $this->em->getRDBRepository('AccountingSync')->where(['connector' => $c->code(), 'deleted' => false, 'status' => ['Pending', 'Failed']])->count();

        return $out;
    }

    /**
     * One attempt on one row. Never throws.
     * @return array{result: string, stop: bool}
     */
    public function attempt(Entity $row, Connector $c): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $claimed = $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('AccountingSync')
                ->set(['lockedUntil' => gmdate('Y-m-d H:i:s', time() + 180)])
                ->where([
                    'id' => $row->getId(), 'deleted' => false, 'status' => ['Pending', 'Failed'],
                    'OR' => [['lockedUntil' => null], ['lockedUntil<' => $now]],
                ])->build()
        )->rowCount();

        if ($claimed < 1) {
            return ['result' => 'pending', 'stop' => false];
        }

        $row = $this->em->getEntityById('AccountingSync', $row->getId());

        try {
            return $this->doAttempt($row, $c);
        } catch (\Throwable $t) {
            return $this->fail($row, $this->cfg->scrub($t->getMessage()), true, false);
        }
    }

    /** @return array{result: string, stop: bool} */
    private function doAttempt(Entity $row, Connector $c): array
    {
        $order = $this->em->getEntityById('WashOrder', (string) $row->get('washOrderId'));

        if (!$order || $order->get('deleted')) {
            return $this->finish($row, 'Skipped', ['error' => 'order_deleted']);
        }

        $doc = $this->builder->build($order);

        if (!empty($doc['skip'])) {
            return $this->finish($row, 'Skipped', ['error' => (string) $doc['skip']]);
        }

        try {
            if ($row->get('kind') === 'Payment') {
                $sale = $this->em->getRDBRepository('AccountingSync')->where(['washOrderId' => $order->getId(), 'connector' => $c->code(), 'kind' => 'Sale', 'status' => 'Sent', 'deleted' => false])->findOne();

                if (!$sale) {
                    return $this->finish($row, 'Skipped', ['error' => 'sale_not_sent']);
                }

                $res = $c->pushPayment($doc, (string) $sale->get('externalId'), (float) $doc['gross'], gmdate('Y-m-d'));

                return $this->finish($row, 'Sent', ['externalId' => $res['externalId'], 'amount' => $doc['gross'], 'docHash' => $doc['hash']]);
            }

            $res = $c->pushSale($doc);

            return $this->finish($row, 'Sent', [
                'externalId' => $res['externalId'], 'externalNo' => $res['externalNo'], 'amount' => $doc['gross'], 'docHash' => $doc['hash'],
                'paidIncluded' => (bool) $doc['paid'], 'error' => !empty($res['adopted']) ? 'adopted_existing' : '',
            ]);
        } catch (ConnectorException $e) {
            return $this->fail($row, $this->cfg->scrub($e->getMessage()), $e->isTransient(), $e->isRateLimited());
        }
    }

    private function finish(Entity $row, string $status, array $set): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $row->set(array_merge([
            'status' => $status, 'lastAttemptAt' => $now, 'nextAttemptAt' => null, 'lockedUntil' => null,
            'attempts' => (int) $row->get('attempts') + ($status === 'Skipped' ? 0 : 1),
            'error' => '', 'sentAt' => $status === 'Sent' ? $now : null,
        ], $set));
        $this->em->saveEntity($row, self::OPT);

        return ['result' => strtolower($status), 'stop' => false];
    }

    /** @return array{result: string, stop: bool} */
    private function fail(Entity $row, string $message, bool $transient, bool $rate): array
    {
        $n = (int) $row->get('attempts') + 1;
        $delay = $transient ? Backoff::delay($n) : null;

        if ($rate) {
            $delay = max($delay ?? 0, 120);
        }

        $row->set([
            'status' => 'Failed', 'attempts' => $n, 'lastAttemptAt' => gmdate('Y-m-d H:i:s'), 'lockedUntil' => null,
            'error' => mb_substr($message, 0, 480),
            'nextAttemptAt' => $delay === null ? null : gmdate('Y-m-d H:i:s', time() + $delay),
        ]);
        $this->em->saveEntity($row, self::OPT);

        return ['result' => 'failed', 'stop' => $rate];
    }

    /** "Send again": a Failed or Skipped row becomes Pending with a clean slate. A Sent row is refused (returns false). */
    public function resend(string $rowId): bool
    {
        $row = $this->em->getEntityById('AccountingSync', $rowId);

        if (!$row || $row->get('deleted') || !in_array($row->get('status'), ['Failed', 'Skipped'], true)) {
            return false;
        }

        $row->set(['status' => 'Pending', 'attempts' => 0, 'error' => '', 'nextAttemptAt' => null, 'lockedUntil' => null]);
        $this->em->saveEntity($row, self::OPT);

        return true;
    }

    /** Re-queue every Failed row (not Sent, not Skipped). */
    public function resendFailed(): int
    {
        $n = 0;

        foreach ($this->em->getRDBRepository('AccountingSync')->where(['status' => 'Failed', 'deleted' => false])->limit(0, 500)->find() as $r) {
            $n += $this->resend($r->getId()) ? 1 : 0;
        }

        return $n;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $out = [];

        foreach (['Pending', 'Sent', 'Failed', 'Skipped'] as $s) {
            $out[$s] = $this->em->getRDBRepository('AccountingSync')->where(['status' => $s, 'deleted' => false])->count();
        }

        return $out;
    }

    public function statusOf(string $rowId): ?string
    {
        $row = $this->em->getEntityById('AccountingSync', $rowId);

        return $row && !$row->get('deleted') ? (string) $row->get('status') : null;
    }

    // ------------------------------------------------------------------ summary

    /**
     * Counts, recent failures, completed orders that never reached the queue, and Sent documents whose order changed afterwards.
     * @return array<string, mixed>
     */
    public function summary(string $from, string $to): array
    {
        $c = $this->registry->active();
        $code = $c ? $c->code() : '';
        $counts = ['Pending' => 0, 'Sent' => 0, 'Failed' => 0, 'Skipped' => 0];
        $failed = [];
        $drift = [];
        $missing = [];
        $utc = new \DateTimeZone('UTC');
        $fromU = (new \DateTime($from . ' 00:00:00', $this->cfg->timeZone()))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toU = (new \DateTime($to . ' 23:59:59', $this->cfg->timeZone()))->setTimezone($utc)->format('Y-m-d H:i:s');
        $orders = $this->em->getRDBRepository('WashOrder')
            ->where(['status' => 'Completed', 'orderDateTime>=' => $fromU, 'orderDateTime<=' => $toU, 'deleted' => false])
            ->order('orderDateTime', 'ASC')->limit(0, 2000)->find();
        $ordersTotal = 0.0;
        $sentTotal = 0.0;

        foreach ($orders as $o) {
            $gross = round((float) $o->get('finalPrice'), 2);
            $row = $code === '' ? null : $this->em->getRDBRepository('AccountingSync')->where(['washOrderId' => $o->getId(), 'connector' => $code, 'kind' => 'Sale', 'deleted' => false])->findOne();

            if ($gross > 0) {
                $ordersTotal += $gross;
            }

            if (!$row) {
                if ($gross > 0 && count($missing) < 50) {
                    $missing[] = ['order' => (string) $o->get('name'), 'orderId' => $o->getId(), 'gross' => $gross];
                }

                continue;
            }

            $counts[$row->get('status')] = ($counts[$row->get('status')] ?? 0) + 1;

            if ($row->get('status') === 'Failed' && count($failed) < 50) {
                $failed[] = ['id' => $row->getId(), 'order' => (string) $o->get('name'), 'error' => (string) $row->get('error'), 'attempts' => (int) $row->get('attempts'), 'next' => $row->get('nextAttemptAt')];
            }

            if ($row->get('status') === 'Sent') {
                $sentTotal += (float) $row->get('amount');
                $doc = $this->builder->build($o);

                if (empty($doc['skip']) && ($doc['hash'] !== (string) $row->get('docHash')) && count($drift) < 50) {
                    $drift[] = ['order' => (string) $o->get('name'), 'orderId' => $o->getId(), 'sent' => (float) $row->get('amount'), 'now' => (float) $doc['gross']];
                }
            }
        }

        return [
            'provider' => $code,
            'counts' => $counts,
            'ordersTotal' => round($ordersTotal, 2),
            'sentTotal' => round($sentTotal, 2),
            'failed' => $failed,
            'missing' => $missing,
            'drift' => $drift,
        ];
    }
}
