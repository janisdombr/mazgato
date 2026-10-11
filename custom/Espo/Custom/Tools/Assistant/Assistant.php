<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\Core\Acl;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * One question from a signed-in user, start to finish:
 *
 *   gates (module, role, configured, limits)  ->  journal row (Pending)  ->  model + tool loop
 *   ->  journal row completed  ->  names put back for the operator's screen.
 *
 * Everything the model sees is built here: the (masked) question, a few earlier answers of the same
 * user, the tools bound to THIS user's ACL. The journal row exists before the first byte goes to
 * the provider.
 */
class Assistant
{
    public const CONTEXT_MINUTES = 30;
    public const CONTEXT_ROWS = 3;
    private const PAGE_OK = '/^[A-Za-z][A-Za-z0-9]{1,30}$/';

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Log $log,
        private InjectableFactory $factory,
        private Modules $modules,
        private AssistantConfig $cfg,
        private Journal $journal,
        private TestHarness $harness
    ) {}

    /** Modules + role. @return string|null error code, null = allowed */
    public function denial(): ?string
    {
        if (!$this->modules->moduleEnabled(Modules::ASSISTANT)) {
            return 'module_disabled';
        }

        if (!$this->allowed()) {
            return 'forbidden';
        }

        return null;
    }

    public function allowed(): bool
    {
        return $this->user->isAdmin() || $this->acl->checkScope('AssistantMessage', 'read');
    }

    /** @return array<string, mixed> what the panel needs to decide which state to show */
    public function status(): array
    {
        $day = $this->journal->questionsToday($this->user->getId());
        $m = $this->journal->monthTotals();

        return [
            'enabled' => true,
            'configured' => $this->cfg->isConfigured(),
            'provider' => $this->cfg->provider(),
            'piiLevel' => $this->cfg->piiLevel(),
            'questionMax' => AssistantConfig::QUESTION_CHARS,
            'limits' => [
                'dailyPerUser' => $this->cfg->dailyPerUser(),
                'usedToday' => $day,
                'leftToday' => max(0, $this->cfg->dailyPerUser() - $day),
                'monthExhausted' => $m['questions'] >= $this->cfg->monthlyQuestions() || $m['tokens'] >= $this->cfg->monthlyTokens(),
            ],
            'canAdmin' => $this->user->isAdmin(),
        ];
    }

    /** @return string|null limit code when the question must be refused */
    public function limitHit(): ?string
    {
        $uid = $this->user->getId();

        if ($this->journal->pendingFor($uid, 180) > 0) {
            return 'busy';
        }

        if ($this->journal->questionsToday($uid) >= $this->cfg->dailyPerUser()) {
            return 'limit_user_day';
        }

        $m = $this->journal->monthTotals();

        if ($m['questions'] >= $this->cfg->monthlyQuestions()) {
            return 'limit_month_questions';
        }

        if ($m['tokens'] >= $this->cfg->monthlyTokens()) {
            return 'limit_month_tokens';
        }

        return null;
    }

    private function userLanguage(): string
    {
        $p = $this->em->getEntityById('Preferences', $this->user->getId());
        $l = $p ? (string) $p->get('language') : '';

        return match (substr($l ?: $this->cfg->language(), 0, 2)) {
            'ru' => 'Russian', 'en' => 'English', default => 'Latvian',
        };
    }

    private function model(): Model
    {
        if ($this->cfg->provider() === 'scripted') {
            return new ScriptedModel($this->harness->script(), fn($c) => $this->harness->record($c));
        }

        $main = new AnthropicModel($this->cfg, $this->cfg->model(), $this->log);
        $fb = $this->cfg->fallbackModel() !== $this->cfg->model() ? new AnthropicModel($this->cfg, $this->cfg->fallbackModel(), $this->log, false) : null;

        return new FallbackModel($main, $fb);
    }

    /**
     * @return array<string, mixed>
     */
    public function ask(string $rawQuestion, string $page): array
    {
        $t0 = microtime(true);
        $question = Mask::inText(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $rawQuestion)));
        $page = preg_match(self::PAGE_OK, $page) ? $page : '';

        // 1. the question is on record before the provider hears of it
        $row = $this->journal->open($this->user->getId(), $question, $page);

        // 2. context: this user's last few answers, with their pseudonym numbering
        $history = [];
        $seed = [];

        foreach ($this->journal->recent($this->user->getId(), self::CONTEXT_MINUTES, self::CONTEXT_ROWS) as $prev) {
            $history[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => (string) $prev->get('question')]]];
            $history[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => (string) $prev->get('answer')]]];
            $seed = array_merge($seed, (array) json_decode((string) $prev->get('pseudonyms'), true));
        }

        $ps = new Pseudonymiser($seed);
        $ctx = new ToolContext($this->em, $this->acl, $this->user, $this->cfg, $ps, $this->factory);
        $registry = new ToolRegistry($ctx);

        $system = Prompt::system([
            'role' => $this->user->isAdmin() ? 'administrator' : 'staff user',
            'language' => $this->userLanguage(),
            'page' => $page,
            'business' => $this->cfg->businessName(),
            'today' => $ctx->today(),
            'timeZone' => $this->cfg->timeZone(),
            'pii' => $this->cfg->piiLevel(),
        ]);

        $result = new TurnResult();

        try {
            $result = (new Loop())->run(
                $this->model(),
                $system,
                $history,
                $question,
                $registry->specs(),
                fn(string $n, array $a) => $registry->run($n, $a),
                $this->cfg
            );
        } catch (\Throwable $e) {
            $this->log->error('Assistant: ' . get_class($e) . ': ' . $this->cfg->key()->scrub($e->getMessage()));
            $result->stopped = 'provider_error';
            $result->errorCode = 'internal_error';
            $result->text = 'The assistant failed to answer. Nothing was changed.';
        }

        foreach ($registry->errors as $err) {
            $this->log->warning('Assistant tool failure: ' . $err);
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->journal->close($row, $result, $ps->map(), $ms);

        return [
            'id' => $row->getId(),
            'status' => $row->get('status'),
            'stopped' => $result->stopped,
            'errorCode' => $result->errorCode,
            'answer' => $this->resolveNames($result->text, $ps),
            'sources' => $this->sources($result->tools),
            'drafts' => $result->drafts,
            'unsourced' => $result->unsourced,
            'usage' => [
                'steps' => $result->steps, 'tokensIn' => $result->tokensIn, 'tokensOut' => $result->tokensOut,
                'cacheRead' => $result->cacheRead, 'costUsd' => round($result->costUsd, 6),
                'costEur' => round($result->costUsd * $this->cfg->usdToEur(), 6), 'ms' => $ms,
            ],
            'model' => implode(', ', $result->models),
        ];
    }

    /** @param array<int, array<string, mixed>> $tools */
    public function sources(array $tools): array
    {
        $out = [];

        foreach ($tools as $t) {
            $out[] = ['tool' => $t['name'], 'source' => $t['source'] ?? null, 'period' => $t['period'] ?? null, 'error' => $t['error'] ?? null];
        }

        return $out;
    }

    /** K-n -> real name, for customers THIS user may see (checked now, not when the answer was made). */
    public function resolveNames(string $text, Pseudonymiser $ps): string
    {
        return $ps->resolve($text, function (string $id): ?string {
            $c = $this->em->getEntityById('Customer', $id);

            if (!$c || !$this->acl->checkEntityRead($c)) {
                return null;
            }

            return $this->user->isAdmin() || $this->acl->checkField('Customer', 'name', 'read') ? (string) $c->get('name') : null;
        });
    }

    /**
     * The user's own history, newest first, with names put back.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $limit): array
    {
        $rows = $this->em->getRDBRepository('AssistantMessage')->where([
            'assignedUserId' => $this->user->getId(), 'deleted' => false,
        ])->order('createdAt', 'DESC')->limit(0, max(1, min(100, $limit)))->find();
        $out = [];

        foreach ($rows as $r) {
            $ps = new Pseudonymiser((array) json_decode((string) $r->get('pseudonyms'), true));
            $tools = (array) json_decode((string) $r->get('toolsJson'), true);
            $out[] = [
                'id' => $r->getId(),
                'at' => $r->get('createdAt'),
                'question' => (string) $r->get('question'),
                'answer' => $this->resolveNames((string) $r->get('answer'), $ps),
                'status' => (string) $r->get('status'),
                'stopped' => (string) $r->get('stopped'),
                'sources' => $this->sources($tools),
                'drafts' => (array) json_decode((string) $r->get('draftsJson'), true),
                'unsourced' => array_values(array_filter(array_map('trim', explode(',', (string) $r->get('unsourced'))))),
                'costUsd' => (float) $r->get('costUsd'),
            ];
        }

        return $out;
    }

    /**
     * Admin "test the connection": one tiny call, journaled like any other so the cost is counted.
     *
     * @return array<string, mixed>
     */
    public function test(): array
    {
        if (!$this->cfg->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $t0 = microtime(true);
        $row = $this->journal->open($this->user->getId(), '(connection test)', 'Admin');
        $r = new TurnResult();

        try {
            $reply = $this->model()->respond(
                [['type' => 'text', 'text' => 'You test a connection. Answer with the single word OK.']],
                [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'ping']]]],
                [],
                20
            );
            $model = $reply->model ?: $this->cfg->model();
            $r->steps = 1;
            $r->tokensIn = $reply->inputTokens;
            $r->tokensOut = $reply->outputTokens;
            $r->cacheRead = $reply->cacheReadTokens;
            $r->models = [$model];
            $r->costUsd = Pricing::costUsd($model, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $reply->cacheWriteTokens);
            $r->text = $reply->text() ?: 'OK';
            $r->stopped = 'answered';
            $ok = true;
            $err = '';
        } catch (ModelError $e) {
            $r->stopped = 'provider_error';
            $r->errorCode = $e->code2;
            $r->text = $this->cfg->key()->scrub($e->getMessage());
            $ok = false;
            $err = $r->text;
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->journal->close($row, $r, [], $ms);

        return ['ok' => $ok, 'error' => $err, 'model' => implode(', ', $r->models), 'ms' => $ms, 'tokensIn' => $r->tokensIn, 'tokensOut' => $r->tokensOut];
    }
}
