<?php

namespace Espo\Custom\Tools\ClientAssistant;

use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Assistant\TurnResult;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The public chat's journal (entity ClientChatMessage): one row per visitor question, written
 * BEFORE the model is called. It is also the usage meter (per-IP, per-day, per-month and cost
 * limits are counts and sums over it), so retention never goes below 31 days.
 *
 * Privacy: the visitor is identified only by `ipHash` (HMAC of the address, never the address)
 * and a random `chatId`; `question` holds the text with phone/e-mail already replaced by
 * placeholders; `vault` (the real values the booking needs) is blanked after a day.
 */
class ClientJournal
{
    private const OPT = ['clientChatWrite' => true, 'silent' => true];
    public const VAULT_HOURS = 24;

    public function __construct(private EntityManager $em, private ClientConfig $cfg, private Config $config)
    {}

    public function ipHash(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, (string) $this->config->get('cryptKey', 'x') . '|clientchat'), 0, 24);
    }

    /** @param array<string, string> $vault */
    public function open(string $ipHash, string $chatId, string $lang, string $question, array $vault): Entity
    {
        return $this->em->createEntity('ClientChatMessage', [
            'name' => mb_substr($question, 0, 100),
            'question' => $question,
            'status' => 'Pending',
            'ipHash' => $ipHash,
            'chatId' => $chatId,
            'lang' => $lang,
            'vault' => $vault ? json_encode($vault, JSON_UNESCAPED_UNICODE) : null,
            'model' => $this->cfg->provider() === 'off' ? '' : $this->cfg->model(),
        ], self::OPT);
    }

    public function close(Entity $row, TurnResult $r, int $ms, string $bookingOrder = ''): void
    {
        $failed = $r->stopped === 'provider_error' || $r->stopped === 'refused';

        $row->set([
            'answer' => $r->text,
            'status' => $r->stopped === 'answered' ? 'Answered' : ($failed ? 'Failed' : 'Stopped'),
            'stopped' => $r->stopped,
            'errorCode' => $r->errorCode,
            'toolNames' => mb_substr(implode(', ', array_unique(array_column($r->tools, 'name'))), 0, 255),
            // arguments of tool calls hold what the visitor said (names, plates): keep names + sizes only
            'toolsJson' => json_encode(array_map(fn($t) => ['name' => $t['name'], 'chars' => $t['chars'], 'error' => $t['error']], $r->tools), JSON_UNESCAPED_UNICODE),
            'bookingOrder' => mb_substr($bookingOrder, 0, 60),
            'steps' => $r->steps,
            'tokensIn' => $r->tokensIn,
            'tokensOut' => $r->tokensOut,
            'cacheReadTokens' => $r->cacheRead,
            'cacheWriteTokens' => $r->cacheWrite,
            'costUsd' => round($r->costUsd, 6),
            'model' => mb_substr(implode(', ', $r->models) ?: (string) $row->get('model'), 0, 120),
            'ms' => $ms,
        ]);
        $this->em->saveEntity($row, self::OPT);
    }

    private function repo()
    {
        return $this->em->getRDBRepository('ClientChatMessage');
    }

    /**
     * Earlier answered turns of THIS chat from the same address: the server's own record, so the
     * visitor cannot forge what "the assistant said".
     *
     * @return Entity[]
     */
    public function history(string $chatId, string $ipHash): array
    {
        $rows = $this->repo()->where([
            'chatId' => $chatId, 'ipHash' => $ipHash, 'status' => 'Answered', 'deleted' => false,
            'createdAt>=' => gmdate('Y-m-d H:i:s', time() - ClientConfig::HISTORY_MINUTES * 60),
        ])->order('createdAt', 'DESC')->limit(0, ClientConfig::HISTORY_TURNS)->find();

        return array_reverse(iterator_to_array($rows, false));
    }

    /** @return array<string, string> placeholder => value, over this chat's recent rows */
    public function vaultFor(string $chatId, string $ipHash): array
    {
        $map = [];
        $rows = $this->repo()->where([
            'chatId' => $chatId, 'ipHash' => $ipHash, 'deleted' => false,
            'createdAt>=' => gmdate('Y-m-d H:i:s', time() - self::VAULT_HOURS * 3600),
        ])->order('createdAt', 'ASC')->limit(0, 50)->find();

        foreach ($rows as $r) {
            $v = json_decode((string) $r->get('vault'), true);

            if (is_array($v)) {
                $map = $v + $map;
            }
        }

        return $map;
    }

    public function pendingFor(string $ipHash, int $seconds): int
    {
        return $this->repo()->where([
            'ipHash' => $ipHash, 'status' => 'Pending', 'deleted' => false,
            'createdAt>=' => gmdate('Y-m-d H:i:s', time() - $seconds),
        ])->count();
    }

    private function dayStartUtc(): string
    {
        $tz = new \DateTimeZone($this->cfg->timeZone());

        return (new \DateTimeImmutable('today', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function monthStartUtc(): string
    {
        $tz = new \DateTimeZone($this->cfg->timeZone());

        return (new \DateTimeImmutable('first day of this month 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function questionsTodayFor(string $ipHash): int
    {
        return $this->repo()->where(['ipHash' => $ipHash, 'deleted' => false, 'createdAt>=' => $this->dayStartUtc()])->count();
    }

    public function bookingsTodayFor(string $ipHash): int
    {
        return $this->repo()->where(['ipHash' => $ipHash, 'deleted' => false, 'bookingOrder!=' => '', 'createdAt>=' => $this->dayStartUtc()])->count();
    }

    public function questionsToday(): int
    {
        return $this->repo()->where(['deleted' => false, 'createdAt>=' => $this->dayStartUtc()])->count();
    }

    /** @return array{questions: int, tokens: int, costUsd: float, bookings: int, since: string} */
    public function monthTotals(): array
    {
        $where = ['deleted' => false, 'createdAt>=' => $this->monthStartUtc()];
        $q = $this->em->getQueryBuilder()->select()->from('ClientChatMessage')
            ->select([['COUNT:id', 'n'], ['SUM:tokensIn', 'ti'], ['SUM:tokensOut', 'to'], ['SUM:cacheReadTokens', 'cr'], ['SUM:cacheWriteTokens', 'cw'], ['SUM:costUsd', 'c']])
            ->where($where)->build();
        $r = $this->em->getQueryExecutor()->execute($q)->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'questions' => (int) ($r['n'] ?? 0),
            'tokens' => (int) ($r['ti'] ?? 0) + (int) ($r['to'] ?? 0) + (int) ($r['cr'] ?? 0) + (int) ($r['cw'] ?? 0),
            'costUsd' => round((float) ($r['c'] ?? 0), 4),
            'bookings' => $this->repo()->where($where + ['bookingOrder!=' => ''])->count(),
            'since' => $this->monthStartUtc(),
        ];
    }

    /**
     * Retention: rows older than the retention are deleted for real; the vault of anything older
     * than a day is blanked (a booking needs it only while the conversation is alive).
     *
     * @return int rows removed (or that would be)
     */
    public function purge(bool $dry = false, ?int $days = null): int
    {
        $old = gmdate('Y-m-d H:i:s', time() - ($days ?? $this->cfg->retentionDays()) * 86400);
        $n = 0;

        foreach ($this->repo()->where(['deleted' => false, 'createdAt<' => $old])->find() as $row) {
            $n++;

            if (!$dry) {
                $this->em->removeEntity($row, self::OPT);
                $this->em->getQueryExecutor()->execute(
                    $this->em->getQueryBuilder()->delete()->from('ClientChatMessage')->where(['id' => $row->getId()])->build()
                );
            }
        }

        if (!$dry) {
            $this->em->getQueryExecutor()->execute(
                $this->em->getQueryBuilder()->update()->in('ClientChatMessage')->set(['vault' => null])
                    ->where(['vault!=' => null, 'createdAt<' => gmdate('Y-m-d H:i:s', time() - self::VAULT_HOURS * 3600)])->build()
            );
        }

        return $n;
    }
}
