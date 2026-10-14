<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Custom\Tools\Assistant\Providers;
use Espo\Custom\Tools\Booking\FormToken;
use Espo\Custom\Tools\Booking\RateLimiter;
use Espo\Custom\Tools\ClientAssistant\ChatService;
use Espo\Custom\Tools\ClientAssistant\ClientConfig;
use Espo\Custom\Tools\ClientAssistant\ClientHarness;
use Espo\Custom\Tools\ClientAssistant\ClientJournal;
use Espo\Custom\Tools\ClientAssistant\Details;
use Espo\Custom\Tools\ClientAssistant\Knowledge;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Entities\User;
use stdClass;

/**
 * POST /api/v1/ClientAssistant/action/<name>
 *
 * PUBLIC (no login; routes.json noAuth - the visitor is anonymous):
 *   config   what the chat widget needs: is it on / available, brand, phone, a signed form token
 *   ask      { question, chatId?, lang?, token, website, details? }  one question -> one answer (details = the form: name, phone, plate, email?, promo?, referral?)
 *            200 {ok, state: answered|error|unavailable, answer, booking?, needDetails, chatId}
 *            429 {state: limit_*|busy|rate_limited}   400 {error: bad-token|too-fast|expired|empty_question|question_too_long|details_invalid, fields?}
 *            403 {error: module_disabled}
 *
 * ADMINISTRATOR ONLY (login; the generic route): adminGet / adminSave / adminTest / adminResetRate /
 * usage, and - only while the shared assistant test mode is on - testScript / testCalls.
 *
 * Like the public booking endpoints these run as the system user, so every answer is built field by
 * field; no entity is serialised and no customer data is ever returned. The chat can write exactly
 * one thing - a booking, through Booking::postActionCreate (see Toolbox).
 */
class ClientAssistant
{
    /** key => [type, secret?|max]  everything the administration page may write. */
    private const KEYS = [
        'clientAssistantProvider' => ['enum'], 'assistantApiKey' => ['key', true], 'clientAssistantApiKey' => ['key', true],
        'clientAssistantModel' => ['model'], 'clientAssistantFallbackModel' => ['model'],
        'clientAssistantPerIpMinute' => ['int'], 'clientAssistantPerIpHour' => ['int'], 'clientAssistantPerIpDay' => ['int'],
        'clientAssistantDayQuestions' => ['int'], 'clientAssistantMonthQuestions' => ['int'], 'clientAssistantMonthTokens' => ['int'],
        'clientAssistantMonthBudgetEur' => ['float'], 'clientAssistantBookingsPerIpDay' => ['int'],
        'clientAssistantMaxSteps' => ['int'], 'clientAssistantTurnSeconds' => ['int'], 'clientAssistantTurnTokens' => ['int'],
        'clientAssistantMaxOutputTokens' => ['int'], 'clientAssistantRetentionDays' => ['int'],
        'clientAssistantAddress' => ['str', 200], 'clientAssistantPhone' => ['str', 40], 'clientAssistantEmail' => ['str', 100],
        'clientAssistantPayments' => ['str', 600], 'clientAssistantFaq' => ['str', 4000],
    ];

    public function __construct(
        private User $user,
        private Config $config,
        private ConfigWriter $writer,
        private Modules $modules,
        private ClientConfig $cfg,
        private ChatService $service,
        private ClientJournal $journal,
        private ClientHarness $harness,
        private Knowledge $knowledge,
        private FormToken $token,
        private RateLimiter $limiter,
        DeviceGuard $deviceGuard
    ) {
        // A tablet's key is a device, not a visitor and not an administrator.
        $deviceGuard->denyDevice($user);
    }

    private function refuse(Response $r, int $status, array $body): null
    {
        $r->setStatus($status);
        $r->setHeader('Content-Type', 'application/json');
        $r->setHeader('Cache-Control', 'no-store');

        if ($status === 429) {
            $r->setHeader('Retry-After', '60');
        }

        $r->writeBody((string) json_encode(['ok' => false] + $body, JSON_UNESCAPED_UNICODE));

        return null;
    }

    private function ip(Request $req): string
    {
        return (string) ($req->getHeader('X-Real-IP') ?: $req->getServerParam('REMOTE_ADDR') ?: '0');
    }

    private function str(?stdClass $d, string $k, int $max): string
    {
        $v = $d->$k ?? '';

        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    private function admin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }
    }

    // ------------------------------------------------------------------ public

    public function postActionConfig(Request $req, Response $res): ?stdClass
    {
        if (!$this->limiter->hit('ca-read', $this->ip($req), 120, 600)) {
            return $this->refuse($res, 429, ['error' => 'rate_limited']);
        }

        $res->setHeader('Cache-Control', 'no-store');

        if (!$this->modules->moduleEnabled(Modules::CLIENT_ASSISTANT)) {
            return (object) ['ok' => true, 'enabled' => false, 'available' => false, 'reason' => 'module_disabled'];
        }

        $available = $this->service->available();
        $bookable = $this->modules->moduleEnabled(Modules::BOOKING) && $this->cfg->bookingsPerIpDay() > 0;

        return (object) [
            'ok' => true,
            'enabled' => true,
            'available' => $available,
            'reason' => $available ? '' : ($this->cfg->isConfigured() ? 'limit' : 'not_configured'),
            'brand' => $this->knowledge->businessName(),
            'phone' => $this->knowledge->phone(),
            'tel' => $this->knowledge->telHref(),
            'bookingPage' => $this->modules->moduleEnabled(Modules::BOOKING) ? '/booking/' : '',
            'canBook' => $bookable,
            'language' => substr((string) $this->config->get('language', 'lv_LV'), 0, 2),
            'questionMax' => ClientConfig::QUESTION_MAX,
            'promo' => $this->knowledge->codes()['promo'],
            'referral' => $this->knowledge->codes()['referral'],
            'token' => $this->token->issue(),
        ];
    }

    public function postActionAsk(Request $req, Response $res): ?stdClass
    {
        $res->setHeader('Cache-Control', 'no-store');

        if (!$this->modules->moduleEnabled(Modules::CLIENT_ASSISTANT)) {
            return $this->refuse($res, 403, ['error' => 'module_disabled']);
        }

        $ip = $this->ip($req);

        // 1. cheap per-address burst limits first (file buckets)
        if ($code = $this->service->burstLimit($ip)) {
            return $this->refuse($res, 429, ['state' => $code, 'error' => 'rate_limited']);
        }

        $d = $req->getParsedBody();

        // 2. honeypot: a human never sees this field. Answer like a success, store and spend nothing.
        if ($this->str($d, 'website', 200) !== '') {
            return (object) ['ok' => true, 'state' => 'answered', 'answer' => '', 'booking' => null, 'chatId' => bin2hex(random_bytes(8))];
        }

        // 3. the signed, aged form token issued by `config` (also what the booking code will check)
        $minAge = $this->config->get('bookingMinFillSeconds');
        $token = $this->str($d, 'token', 200);

        if ($bad = $this->token->check($token, $minAge === null || $minAge === '' ? 2 : (int) $minAge)) {
            return $this->refuse($res, 400, ['error' => $bad]);
        }

        // 4. the question
        $q = $this->str($d, 'question', ClientConfig::QUESTION_MAX + 1);

        // 4b. the details form (name / phone / plate / e-mail / codes): hard validation, before anything is stored or sent
        $details = null;

        if (isset($d->details)) {
            $codes = $this->knowledge->codes();
            $v = Details::validate($d->details, $codes['promo'], $codes['referral']);

            if (!$v['ok']) {
                return $this->refuse($res, 400, ['error' => 'details_invalid', 'fields' => $v['fields']]);
            }

            $details = $v['values'];
        }

        if ($q === '' && !$details) {
            return $this->refuse($res, 400, ['error' => 'empty_question']);
        }

        if (mb_strlen($q) > ClientConfig::QUESTION_MAX) {
            return $this->refuse($res, 400, ['error' => 'question_too_long', 'max' => ClientConfig::QUESTION_MAX]);
        }

        // 5. honest "not available": no key / provider off - nothing is stored, nothing is sent
        if (!$this->cfg->isConfigured()) {
            return (object) ['ok' => false, 'state' => 'unavailable', 'reason' => 'not_configured'];
        }

        // 6. journal-backed limits: busy, per address per day, whole installation per day / month / money
        $ipHash = $this->journal->ipHash($ip);

        if ($limit = $this->service->limitHit($ipHash)) {
            return $this->refuse($res, 429, ['state' => $limit, 'error' => $limit]);
        }

        $chatId = $this->str($d, 'chatId', 32);
        $chatId = preg_match('/^[a-f0-9]{16,32}$/', $chatId) ? $chatId : bin2hex(random_bytes(8));
        $lang = $this->str($d, 'lang', 2);
        $lang = in_array($lang, ['lv', 'ru', 'en', 'et', 'lt'], true) ? $lang : substr((string) $this->config->get('language', 'lv_LV'), 0, 2);

        // The journal row must be completed even if the browser or the proxy gives up waiting.
        ignore_user_abort(true);
        @set_time_limit($this->cfg->turnSeconds() + 20);

        $out = $this->service->ask($q, $chatId, $lang, $ip, $token, $details);

        return (object) (['chatId' => $chatId] + $out);
    }

    // ------------------------------------------------------------------ administration

    public function postActionAdminGet(): stdClass
    {
        $this->admin();
        $values = [];
        $secrets = [];

        foreach (self::KEYS as $k => $def) {
            if (!empty($def[1]) && $def[0] === 'key') {
                $secrets[$k] = trim((string) $this->config->get($k, '')) !== '';
            } else {
                $values[$k] = $this->config->get($k);
            }
        }

        $eff = [
            'clientAssistantProvider' => $this->cfg->provider() === 'scripted' ? 'off' : $this->cfg->provider(),
            'clientAssistantModel' => $this->cfg->model(), 'clientAssistantFallbackModel' => $this->cfg->fallbackModel(),
            'clientAssistantPerIpMinute' => $this->cfg->perIpMinute(), 'clientAssistantPerIpHour' => $this->cfg->perIpHour(),
            'clientAssistantPerIpDay' => $this->cfg->perIpDay(), 'clientAssistantDayQuestions' => $this->cfg->dayQuestions(),
            'clientAssistantMonthQuestions' => $this->cfg->monthQuestions(), 'clientAssistantMonthTokens' => $this->cfg->monthTokens(),
            'clientAssistantMonthBudgetEur' => $this->cfg->monthBudgetEur(), 'clientAssistantBookingsPerIpDay' => $this->cfg->bookingsPerIpDay(),
            'clientAssistantMaxSteps' => $this->cfg->maxSteps(), 'clientAssistantTurnSeconds' => $this->cfg->turnSeconds(),
            'clientAssistantTurnTokens' => $this->cfg->turnInputTokens(), 'clientAssistantMaxOutputTokens' => $this->cfg->maxOutputTokens(),
            'clientAssistantRetentionDays' => $this->cfg->retentionDays(),
            'clientAssistantEnabled' => $this->modules->moduleEnabled(Modules::CLIENT_ASSISTANT),
        ];

        $site = $this->cfg->siteUrl();

        return (object) [
            'values' => (object) array_merge($values, $eff),
            'secrets' => (object) $secrets,
            'module' => $this->modules->moduleEnabled(Modules::CLIENT_ASSISTANT),
            'bookingModule' => $this->modules->moduleEnabled(Modules::BOOKING),
            'configured' => $this->cfg->isConfigured(),
            'testMode' => $this->cfg->testMode(),
            'pageUrl' => $site !== '' ? $site . '/booking/chat/' : '/booking/chat/',
            'bookingUrl' => $site !== '' ? $site . '/booking/' : '/booking/',
            'ceilings' => ['steps' => ClientConfig::STEPS_CEILING, 'seconds' => ClientConfig::SECONDS_CEILING, 'budgetEur' => ClientConfig::BUDGET_CEILING_EUR],
            'knowledge' => $this->knowledge->block(),
            'providers' => Providers::publicList(),
            'sharedKeyAvailable' => trim((string) $this->config->get('assistantApiKey', '')) !== '',
        ];
    }

    public function postActionAdminSave(Request $req): stdClass
    {
        $this->admin();
        $d = $req->getParsedBody();
        $set = [];

        foreach ((array) ($d->values ?? []) as $k => $v) {
            if ($k === 'clientAssistantEnabled') {
                $set['enabledModules'] = $this->mergeModule((bool) $v);

                continue;
            }

            $def = self::KEYS[$k] ?? null;

            if (!$def) {
                continue;
            }

            if (($def[1] ?? false) === true && ($v === '' || $v === null)) {
                continue; // a blank secret = keep the stored one
            }

            $set[$k] = match ($def[0]) {
                'int' => $v === '' || $v === null ? null : max(0, (int) $v),
                'float' => $v === '' || $v === null ? null : max(0.0, (float) $v),
                'model' => $this->model((string) $v),
                'key' => $this->key((string) $v),
                'str' => $this->text((string) $v, (int) $def[1]),
                default => $this->enum($k, (string) $v),
            };
        }

        foreach ((array) ($d->clear ?? []) as $k) {
            if ($k === 'assistantApiKey' || $k === 'clientAssistantApiKey') {
                $set[$k] = '';
            }
        }

        if ($set) {
            $this->writer->setMultiple($set);
            $this->writer->save();
        }

        return (object) ['ok' => true, 'saved' => array_keys($set)];
    }

    /** @return string[] the saved module list with this module switched; the others are never touched */
    private function mergeModule(bool $on): array
    {
        $cur = $this->config->get('enabledModules');
        $list = is_array($cur) ? array_values($cur) : array_values(array_diff(Modules::ALL, Modules::DEFAULT_OFF));
        $list = array_values(array_diff($list, [Modules::CLIENT_ASSISTANT]));

        if ($on) {
            $list[] = Modules::CLIENT_ASSISTANT;
        }

        return $list;
    }

    private function enum(string $key, string $v): string
    {
        if ($key !== 'clientAssistantProvider' || !in_array($v, array_merge(['off'], Providers::ids()), true)) {
            throw new BadRequest("Invalid value for $key.");
        }

        return $v;
    }

    private function text(string $v, int $max): string
    {
        return trim(mb_substr((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v), 0, $max));
    }

    private function model(string $v): string
    {
        $v = trim($v);

        if ($v !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-:\/]{1,80}$/', $v)) {
            throw new BadRequest('A model id looks like claude-haiku-5-5 or gpt-4.1-nano.');
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

        return (object) $this->service->test($this->journal->ipHash('admin:' . $this->user->getId()));
    }

    public function postActionAdminResetRate(): stdClass
    {
        $this->admin();
        $this->limiter->reset();

        return (object) ['ok' => true];
    }

    public function postActionUsage(): stdClass
    {
        $this->admin();
        $m = $this->journal->monthTotals();
        $rate = $this->cfg->usdToEur();

        return (object) [
            'ok' => true,
            'questions' => $m['questions'], 'tokens' => $m['tokens'], 'bookings' => $m['bookings'],
            'costUsd' => $m['costUsd'], 'costEur' => round($m['costUsd'] * $rate, 4), 'usdToEur' => $rate,
            'today' => $this->journal->questionsToday(),
            'limits' => [
                'dayQuestions' => $this->cfg->dayQuestions(), 'monthQuestions' => $this->cfg->monthQuestions(),
                'monthTokens' => $this->cfg->monthTokens(), 'monthBudgetEur' => $this->cfg->monthBudgetEur(),
            ],
        ];
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
