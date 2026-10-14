<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Custom\Tools\Assistant\Assistant as Service;
use Espo\Custom\Tools\Assistant\AssistantConfig;
use Espo\Custom\Tools\Assistant\Journal;
use Espo\Custom\Tools\Assistant\TestHarness;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Entities\User;
use stdClass;

/**
 * POST /api/v1/Assistant/action/<name>
 *
 *  ask            { question, page? }  one question; answers 200 with the answer, or
 *                                      200 {state: not_configured}, 429 {state: limit_*|busy},
 *                                      403 {error: module_disabled|forbidden}
 *  status         what the panel needs: configured? questions left today?
 *  history        the caller's own journal, newest first, names put back
 *  deleteHistory  removes the caller's own journal rows for good
 *  usage          this month: questions, tokens, cost (admin: whole installation + per user)
 *  adminGet / adminSave / adminTest      administrator only (not gated by the module flag)
 *  testScript / testCalls                administrator, and only while assistantTestMode is on
 *
 * The assistant never writes business data: its tools are read-only, `draft` only fills a form.
 */
class Assistant
{
    /** key => [type, secret?] — everything the administration page may write. */
    private const KEYS = [
        'assistantProvider' => ['enum'], 'assistantApiKey' => ['key', true],
        'assistantModel' => ['model'], 'assistantFallbackModel' => ['model'], 'assistantEffort' => ['enum'],
        'assistantDailyPerUser' => ['int'], 'assistantMonthlyQuestions' => ['int'], 'assistantMonthlyTokens' => ['int'],
        'assistantMaxSteps' => ['int'], 'assistantTurnSeconds' => ['int'], 'assistantTurnTokens' => ['int'], 'assistantMaxOutputTokens' => ['int'],
        'assistantPiiLevel' => ['enum'], 'assistantRetentionDays' => ['int'], 'assistantUsdToEur' => ['float'],
    ];

    public function __construct(
        private User $user,
        private Config $config,
        private ConfigWriter $writer,
        private Modules $modules,
        private AssistantConfig $cfg,
        private Service $service,
        private Journal $journal,
        private TestHarness $harness,
        DeviceGuard $deviceGuard
    ) {
        // A tablet's key is a device, not a person with a role.
        $deviceGuard->denyDevice($user);
    }

    private function refuse(Response $r, int $status, array $body): null
    {
        $r->setStatus($status);
        $r->setHeader('Content-Type', 'application/json');
        $r->writeBody((string) json_encode(['ok' => false] + $body, JSON_UNESCAPED_UNICODE));

        return null;
    }

    private function admin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }
    }

    // ------------------------------------------------------------------ user actions

    public function postActionStatus(Request $req, Response $res): ?stdClass
    {
        if ($d = $this->service->denial()) {
            return $this->refuse($res, 403, ['error' => $d]);
        }

        return (object) (['ok' => true] + $this->service->status());
    }

    public function postActionAsk(Request $req, Response $res): ?stdClass
    {
        if ($d = $this->service->denial()) {
            return $this->refuse($res, 403, ['error' => $d]);
        }

        $body = $req->getParsedBody();
        $q = trim((string) ($body->question ?? ''));

        if ($q === '') {
            return $this->refuse($res, 400, ['error' => 'empty_question']);
        }

        if (mb_strlen($q) > AssistantConfig::QUESTION_CHARS) {
            return $this->refuse($res, 400, ['error' => 'question_too_long', 'max' => AssistantConfig::QUESTION_CHARS]);
        }

        if (!$this->cfg->isConfigured()) {
            // Not an error: the panel explains how to switch the assistant on.
            return (object) ['ok' => false, 'state' => 'not_configured', 'canAdmin' => $this->user->isAdmin()];
        }

        if ($limit = $this->service->limitHit()) {
            $res->setHeader('Retry-After', $limit === 'busy' ? '5' : '3600');

            return $this->refuse($res, 429, ['state' => $limit, 'error' => $limit]);
        }

        // The journal row must be completed even if the browser or the proxy gives up waiting.
        ignore_user_abort(true);
        @set_time_limit($this->cfg->turnSeconds() + 20);

        return (object) (['ok' => true] + $this->service->ask($q, (string) ($body->page ?? '')));
    }

    public function postActionHistory(Request $req, Response $res): ?stdClass
    {
        if ($d = $this->service->denial()) {
            return $this->refuse($res, 403, ['error' => $d]);
        }

        $n = (int) ($req->getParsedBody()->limit ?? 30);

        return (object) ['ok' => true, 'list' => $this->service->history($n)];
    }

    public function postActionDeleteHistory(Request $req, Response $res): ?stdClass
    {
        if ($d = $this->service->denial()) {
            return $this->refuse($res, 403, ['error' => $d]);
        }

        return (object) ['ok' => true, 'removed' => $this->journal->purge(false, null, $this->user->getId())];
    }

    public function postActionUsage(Request $req, Response $res): ?stdClass
    {
        if ($d = $this->service->denial()) {
            return $this->refuse($res, 403, ['error' => $d]);
        }

        $admin = $this->user->isAdmin();
        $m = $this->journal->monthTotals($admin ? null : $this->user->getId());
        $rate = $this->cfg->usdToEur();
        $out = [
            'ok' => true,
            'scope' => $admin ? 'installation' : 'own',
            'questions' => $m['questions'], 'tokens' => $m['tokens'],
            'costUsd' => $m['costUsd'], 'costEur' => round($m['costUsd'] * $rate, 4), 'usdToEur' => $rate,
            'limits' => [
                'monthlyQuestions' => $this->cfg->monthlyQuestions(), 'monthlyTokens' => $this->cfg->monthlyTokens(),
                'dailyPerUser' => $this->cfg->dailyPerUser(), 'usedToday' => $this->journal->questionsToday($this->user->getId()),
            ],
        ];

        if ($admin) {
            $out['byUser'] = $this->journal->monthByUser();
        }

        return (object) $out;
    }

    // ------------------------------------------------------------------ administration

    public function postActionAdminGet(): stdClass
    {
        $this->admin();
        $values = [];
        $secrets = [];

        foreach (self::KEYS as $k => $def) {
            if (!empty($def[1])) {
                $secrets[$k] = trim((string) $this->config->get($k, '')) !== '';
            } else {
                $values[$k] = $this->config->get($k);
            }
        }

        // effective values for blanks, so the page shows what is really in force
        $values += [];
        $eff = [
            'assistantProvider' => $this->cfg->provider() === 'scripted' ? 'off' : $this->cfg->provider(),
            'assistantModel' => $this->cfg->model(), 'assistantFallbackModel' => $this->cfg->fallbackModel(),
            'assistantEffort' => $this->cfg->effort() ?: 'none', 'assistantDailyPerUser' => $this->cfg->dailyPerUser(),
            'assistantMonthlyQuestions' => $this->cfg->monthlyQuestions(), 'assistantMonthlyTokens' => $this->cfg->monthlyTokens(),
            'assistantMaxSteps' => $this->cfg->maxSteps(), 'assistantTurnSeconds' => $this->cfg->turnSeconds(),
            'assistantTurnTokens' => $this->cfg->turnInputTokens(), 'assistantMaxOutputTokens' => $this->cfg->maxOutputTokens(),
            'assistantPiiLevel' => $this->cfg->piiLevel(), 'assistantRetentionDays' => $this->cfg->retentionDays(),
            'assistantUsdToEur' => $this->cfg->usdToEur(),
        ];

        return (object) [
            'values' => (object) array_merge($values, $eff),
            'secrets' => (object) $secrets,
            'module' => $this->modules->moduleEnabled(Modules::ASSISTANT),
            'configured' => $this->cfg->isConfigured(),
            'ceilings' => ['steps' => AssistantConfig::MAX_STEPS_CEILING, 'seconds' => AssistantConfig::TURN_SECONDS_CEILING],
            'testMode' => $this->cfg->testMode(),
        ];
    }

    public function postActionAdminSave(Request $req): stdClass
    {
        $this->admin();
        $d = $req->getParsedBody();
        $set = [];

        foreach ((array) ($d->values ?? []) as $k => $v) {
            $def = self::KEYS[$k] ?? null;

            if (!$def) {
                continue;
            }

            if (!empty($def[1]) && ($v === '' || $v === null)) {
                continue; // a blank secret = keep the stored one
            }

            $set[$k] = match ($def[0]) {
                'int' => $v === '' || $v === null ? null : max(0, (int) $v),
                'float' => $v === '' || $v === null ? null : (float) $v,
                'model' => $this->model((string) $v),
                'key' => $this->key((string) $v),
                default => $this->enum($k, (string) $v),
            };
        }

        foreach ((array) ($d->clear ?? []) as $k) {
            if (!empty(self::KEYS[$k][1])) {
                $set[$k] = '';
            }
        }

        if ($set) {
            $this->writer->setMultiple($set);
            $this->writer->save();
        }

        return (object) ['ok' => true, 'saved' => array_keys($set)];
    }

    private function enum(string $key, string $v): string
    {
        $ok = match ($key) {
            'assistantProvider' => AssistantConfig::PROVIDERS,
            'assistantPiiLevel' => AssistantConfig::PII_LEVELS,
            'assistantEffort' => ['none', 'low', 'medium', 'high'],
            default => [],
        };

        if (!in_array($v, $ok, true)) {
            throw new BadRequest("Invalid value for $key.");
        }

        return $v;
    }

    private function model(string $v): string
    {
        $v = trim($v);

        if ($v !== '' && !preg_match('/^claude-[a-z0-9.\-]{2,60}$/', $v)) {
            throw new BadRequest('A model id looks like claude-sonnet-5-5.');
        }

        return $v;
    }

    private function key(string $v): string
    {
        $v = trim($v);

        if (!preg_match('/^[A-Za-z0-9._\-]{20,300}$/', $v)) {
            throw new BadRequest('That does not look like an API key.');
        }

        return $v;
    }

    public function postActionAdminTest(): stdClass
    {
        $this->admin();

        return (object) $this->service->test();
    }

    // ------------------------------------------------------------------ test support

    private function testing(): void
    {
        $this->admin();

        if (!$this->cfg->testMode()) {
            throw new Forbidden('Test mode is off.');
        }
    }

    public function postActionTestScript(Request $req): stdClass
    {
        $this->testing();
        $d = $req->getParsedBody();

        if (!empty($d->clear)) {
            $this->harness->clear();
        } else {
            $this->harness->setScript(json_decode((string) json_encode($d->script ?? []), true) ?: []);
        }

        return (object) ['ok' => true];
    }

    public function postActionTestCalls(): stdClass
    {
        $this->testing();

        return (object) ['ok' => true, 'calls' => $this->harness->calls()];
    }
}
