<?php

namespace Espo\Custom\Tools\ClientAssistant;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Assistant\ModelFactory;
use Espo\Custom\Tools\Assistant\FallbackModel;
use Espo\Custom\Tools\Assistant\Loop;
use Espo\Custom\Tools\Assistant\Model;
use Espo\Custom\Tools\Assistant\ModelError;
use Espo\Custom\Tools\Assistant\Pricing;
use Espo\Custom\Tools\Assistant\ScriptedModel;
use Espo\Custom\Tools\Assistant\TurnResult;
use Espo\Custom\Tools\Booking\Planner;
use Espo\Custom\Tools\Booking\RateLimiter;

/**
 * One visitor question, start to finish:
 *
 *   limits (per IP / day / month / money)  ->  journal row (Pending, contacts masked)  ->
 *   model + the two-tool loop  ->  journal row completed  ->  a sanitised answer.
 *
 * The visitor is anonymous: nothing here depends on a user, an ACL or a session. What the model
 * can see is the Knowledge block and what its two tools return - see Toolbox.
 */
class ChatService
{
    public function __construct(
        private ClientConfig $cfg,
        private ClientJournal $journal,
        private ClientHarness $harness,
        private Knowledge $knowledge,
        private Planner $planner,
        private RateLimiter $limiter,
        private InjectableFactory $factory,
        private \Espo\ORM\EntityManager $em,
        private Log $log
    ) {}

    /** Per-address burst limits (file buckets, hashed). @return string|null limit code */
    public function burstLimit(string $ip): ?string
    {
        if (!$this->limiter->hit('ca-min', $ip, $this->cfg->perIpMinute(), 60)) {
            return 'limit_ip_minute';
        }

        return $this->limiter->hit('ca-hr', $ip, $this->cfg->perIpHour(), 3600) ? null : 'limit_ip_hour';
    }

    /**
     * Limits that need the journal (and so cost a query) - checked after the burst limits and
     * before anything is sent to a provider. @return string|null limit code
     */
    public function limitHit(string $ipHash): ?string
    {
        if ($this->journal->pendingFor($ipHash, 90) > 0) {
            return 'busy';
        }

        if ($this->journal->questionsTodayFor($ipHash) >= $this->cfg->perIpDay()) {
            return 'limit_ip_day';
        }

        if ($this->journal->questionsToday() >= $this->cfg->dayQuestions()) {
            return 'limit_day';
        }

        $m = $this->journal->monthTotals();

        if ($m['questions'] >= $this->cfg->monthQuestions()) {
            return 'limit_month_questions';
        }

        if ($m['tokens'] >= $this->cfg->monthTokens()) {
            return 'limit_month_tokens';
        }

        if ($m['costUsd'] * $this->cfg->usdToEur() >= $this->cfg->monthBudgetEur()) {
            return 'limit_budget';
        }

        return null;
    }

    /** Could the chat answer right now (provider ready AND no global ceiling reached)? */
    public function available(): bool
    {
        if (!$this->cfg->isConfigured()) {
            return false;
        }

        $m = $this->journal->monthTotals();

        return $this->journal->questionsToday() < $this->cfg->dayQuestions()
            && $m['questions'] < $this->cfg->monthQuestions() && $m['tokens'] < $this->cfg->monthTokens()
            && $m['costUsd'] * $this->cfg->usdToEur() < $this->cfg->monthBudgetEur();
    }

    private function model(): Model
    {
        if ($this->cfg->provider() === 'scripted') {
            return new ScriptedModel($this->harness->script(), fn($c) => $this->harness->record($c));
        }

        return ModelFactory::make($this->cfg, $this->log);
    }

    /** What the visitor may read: no markup, no control characters, bounded. */
    public static function sanitize(string $text): string
    {
        $t = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', strip_tags($text));
        $t = (string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $t));
        $t = trim($t);

        return mb_strlen($t) > ClientConfig::ANSWER_MAX ? mb_substr($t, 0, ClientConfig::ANSWER_MAX - 1) . '…' : $t;
    }

    /**
     * @return array<string, mixed>
     */
    public function ask(string $rawQuestion, string $chatId, string $lang, string $ip, string $token): array
    {
        $t0 = microtime(true);
        $ipHash = $this->journal->ipHash($ip);
        $raw = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $rawQuestion));

        $vault = new ContactVault($this->journal->vaultFor($chatId, $ipHash));
        $question = $vault->protect($raw);

        // 1. on record (contacts already masked) before the provider hears of it
        $row = $this->journal->open($ipHash, $chatId, $lang, $question, $vault->map());

        // 2. context: this chat's earlier answered turns, from OUR journal (a visitor cannot forge them)
        $history = [];

        foreach ($this->journal->history($chatId, $ipHash) as $prev) {
            if ($prev->getId() === $row->getId()) {
                continue;
            }

            $history[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => (string) $prev->get('question')]]];
            $history[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => (string) $prev->get('answer')]]];
        }

        $box = new Toolbox($this->em, $this->planner, $this->factory, $this->cfg, $this->journal, $vault, $ip, $ipHash, $token, $lang, $raw, $history !== []);
        $now = $this->planner->now()->setTimezone($this->planner->tz());
        $system = ClientPrompt::system(
            $this->knowledge->block(), $now->format('Y-m-d l'), $this->cfg->timeZone(), $now->format('H:i'),
            ['lv' => 'Latvian', 'ru' => 'Russian', 'en' => 'English', 'et' => 'Estonian', 'lt' => 'Lithuanian'][$lang] ?? 'Latvian'
        );

        $result = new TurnResult();

        try {
            $result = (new Loop())->run($this->model(), $system, $history, $question, $box->specs(), fn(string $n, array $a) => $box->run($n, $a), $this->cfg);
        } catch (\Throwable $e) {
            $this->log->error('ClientAssistant: ' . get_class($e) . ': ' . $this->cfg->key()->scrub($e->getMessage()));
            $result->stopped = 'provider_error';
            $result->errorCode = 'internal_error';
            $result->text = '';
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->journal->close($row, $result, $ms, (string) ($box->booking['orderNumber'] ?? ''));

        $ok = $result->stopped === 'answered';

        return [
            'ok' => $ok,
            'state' => $ok ? 'answered' : 'error',
            'answer' => $ok ? $vault->restore(self::sanitize($result->text)) : '',
            'booking' => $box->booking,
        ];
    }

    /**
     * Admin "test the connection": one tiny call, journaled like any other so the cost is counted.
     *
     * @return array<string, mixed>
     */
    public function test(string $ipHash): array
    {
        if (!$this->cfg->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $t0 = microtime(true);
        $row = $this->journal->open($ipHash, 'admintest', 'en', '(connection test)', []);
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

        $this->journal->close($row, $r, (int) round((microtime(true) - $t0) * 1000));

        return ['ok' => $ok, 'error' => $err, 'model' => implode(', ', $r->models), 'ms' => (int) round((microtime(true) - $t0) * 1000), 'tokensIn' => $r->tokensIn, 'tokensOut' => $r->tokensOut];
    }
}
