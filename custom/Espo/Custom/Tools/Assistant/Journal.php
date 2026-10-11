<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The assistant's journal (entity AssistantMessage): one row per question, written BEFORE the model
 * is called (status Pending) and completed afterwards. It is also the usage meter — the daily and
 * monthly limits and the cost report are sums over it — so retention never goes below 31 days.
 */
class Journal
{
    private const OPT = ['assistantWrite' => true, 'silent' => true];

    public function __construct(private EntityManager $em, private AssistantConfig $cfg)
    {}

    public function open(string $userId, string $question, string $page): Entity
    {
        return $this->em->createEntity('AssistantMessage', [
            'name' => mb_substr($question, 0, 100),
            'question' => $question,
            'status' => 'Pending',
            'assignedUserId' => $userId,
            'fromPage' => mb_substr($page, 0, 60),
            'model' => $this->cfg->provider() === 'off' ? '' : $this->cfg->model(),
        ], self::OPT);
    }

    /** @param array<string, string> $pseudonyms */
    public function close(Entity $row, TurnResult $r, array $pseudonyms, int $ms): void
    {
        $failed = $r->stopped === 'provider_error' || $r->stopped === 'refused';

        $row->set([
            'answer' => $r->text,
            'status' => $r->stopped === 'answered' ? 'Answered' : ($failed ? 'Failed' : 'Stopped'),
            'stopped' => $r->stopped,
            'errorCode' => $r->errorCode,
            'toolNames' => mb_substr(implode(', ', array_unique(array_column($r->tools, 'name'))), 0, 255),
            'toolsJson' => json_encode($r->tools, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'draftsJson' => $r->drafts ? json_encode(array_map(fn($d) => ['entityType' => $d['entityType'], 'summary' => $d['summary'] ?? ''], $r->drafts), JSON_UNESCAPED_UNICODE) : null,
            'pseudonyms' => $pseudonyms ? json_encode($pseudonyms) : null,
            'unsourced' => mb_substr(implode(', ', $r->unsourced), 0, 255),
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

    /** Rows of this user from the last `$minutes` that were answered: the follow-up context. */
    public function recent(string $userId, int $minutes, int $n): array
    {
        $rows = $this->em->getRDBRepository('AssistantMessage')->where([
            'assignedUserId' => $userId, 'status' => 'Answered', 'deleted' => false,
            'createdAt>=' => gmdate('Y-m-d H:i:s', time() - $minutes * 60),
        ])->order('createdAt', 'DESC')->limit(0, $n)->find();

        return array_reverse(iterator_to_array($rows, false));
    }

    public function pendingFor(string $userId, int $seconds): int
    {
        return $this->em->getRDBRepository('AssistantMessage')->where([
            'assignedUserId' => $userId, 'status' => 'Pending', 'deleted' => false,
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

    public function questionsToday(string $userId): int
    {
        return $this->em->getRDBRepository('AssistantMessage')->where([
            'assignedUserId' => $userId, 'deleted' => false, 'createdAt>=' => $this->dayStartUtc(),
        ])->count();
    }

    /** @return array{questions: int, tokens: int, costUsd: float, since: string} this month, whole installation */
    public function monthTotals(?string $userId = null): array
    {
        $where = ['deleted' => false, 'createdAt>=' => $this->monthStartUtc()];

        if ($userId) {
            $where['assignedUserId'] = $userId;
        }

        $q = $this->em->getQueryBuilder()->select()->from('AssistantMessage')
            ->select([['COUNT:id', 'n'], ['SUM:tokensIn', 'ti'], ['SUM:tokensOut', 'to'], ['SUM:cacheReadTokens', 'cr'], ['SUM:cacheWriteTokens', 'cw'], ['SUM:costUsd', 'c']])
            ->where($where)->build();
        $r = $this->em->getQueryExecutor()->execute($q)->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'questions' => (int) ($r['n'] ?? 0),
            'tokens' => (int) ($r['ti'] ?? 0) + (int) ($r['to'] ?? 0) + (int) ($r['cr'] ?? 0) + (int) ($r['cw'] ?? 0),
            'costUsd' => round((float) ($r['c'] ?? 0), 4),
            'since' => $this->monthStartUtc(),
        ];
    }

    /** @return array<int, array<string, mixed>> per-user totals of this month */
    public function monthByUser(): array
    {
        $q = $this->em->getQueryBuilder()->select()->from('AssistantMessage')
            ->select(['assignedUserId', ['COUNT:id', 'n'], ['SUM:costUsd', 'c'], ['SUM:tokensOut', 'to']])
            ->where(['deleted' => false, 'createdAt>=' => $this->monthStartUtc()])->group(['assignedUserId'])->build();
        $out = [];

        foreach ($this->em->getQueryExecutor()->execute($q)->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $u = $r['assignedUserId'] ? $this->em->getEntityById('User', $r['assignedUserId']) : null;
            $out[] = ['user' => $u ? (string) ($u->get('userName') ?: $u->get('name')) : '—', 'questions' => (int) $r['n'], 'costUsd' => round((float) $r['c'], 4)];
        }

        usort($out, fn($a, $b) => $b['costUsd'] <=> $a['costUsd']);

        return $out;
    }

    /** Delete rows older than the retention (or all of a user's rows). @return int rows removed (or that would be) */
    public function purge(bool $dry = false, ?int $days = null, ?string $userId = null): int
    {
        $where = ['deleted' => false];

        if ($userId) {
            $where['assignedUserId'] = $userId;
        } else {
            $where['createdAt<'] = gmdate('Y-m-d H:i:s', time() - ($days ?? $this->cfg->retentionDays()) * 86400);
        }

        $repo = $this->em->getRDBRepository('AssistantMessage');
        $n = 0;

        foreach ($repo->where($where)->find() as $row) {
            $n++;

            if (!$dry) {
                // removed for real (personal data), not soft-deleted
                $this->em->removeEntity($row, ['assistantWrite' => true, 'silent' => true]);
                $this->em->getQueryExecutor()->execute(
                    $this->em->getQueryBuilder()->delete()->from('AssistantMessage')->where(['id' => $row->getId()])->build()
                );
            }
        }

        return $n;
    }
}
