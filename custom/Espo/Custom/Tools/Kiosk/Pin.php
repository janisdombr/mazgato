<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Worker PIN: policy, storage and brute-force lockout.
 *
 * Only a hash is ever stored: bcrypt over an HMAC of the PIN keyed with the instance's
 * `cryptKey` (which lives in data/config-internal.php, not in the database) and bound to
 * the employee id. So a leaked database does not let a 4-digit PIN be brute-forced
 * offline, and two workers with the same PIN do not share a hash. The hash never leaves
 * the server: `entityAcl/Employee.json` marks it forbidden for the API, and nothing here
 * logs a PIN.
 */
class Pin
{
    public const OK = 'ok';
    public const WRONG = 'wrong';
    public const LOCKED = 'locked';
    public const NOT_SET = 'not_set';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    /** 4–6 digits, not all the same, not a straight run (1234, 6543). */
    public function isAcceptable(string $pin): bool
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            return false;
        }

        if (preg_match('/^(\d)\1+$/', $pin)) {
            return false;
        }

        $up = true;
        $down = true;

        for ($i = 1; $i < strlen($pin); $i++) {
            $d = (int) $pin[$i] - (int) $pin[$i - 1];
            $up = $up && $d === 1;
            $down = $down && $d === -1;
        }

        return !$up && !$down;
    }

    public function set(Entity $employee, string $pin): void
    {
        $employee->set([
            'pinHash' => password_hash($this->material($employee, $pin), PASSWORD_BCRYPT),
            'pinFailedCount' => 0,
            'pinLockedUntil' => null,
            'pinSetAt' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->entityManager->saveEntity($employee, ['silent' => true]);
    }

    public function unlock(Entity $employee): void
    {
        $employee->set(['pinFailedCount' => 0, 'pinLockedUntil' => null]);
        $this->entityManager->saveEntity($employee, ['silent' => true]);
    }

    public function maxAttempts(): int
    {
        return max(1, (int) ($this->config->get('kioskPinMaxAttempts') ?: 5));
    }

    public function lockMinutes(): int
    {
        return max(1, (int) ($this->config->get('kioskPinLockMinutes') ?: 15));
    }

    /**
     * @return array{result: string, attemptsLeft?: int, lockedUntil?: ?string}
     */
    public function check(Entity $employee, string $pin): array
    {
        $now = gmdate('Y-m-d H:i:s');

        if (!$employee->get('pinHash')) {
            return ['result' => self::NOT_SET];
        }

        $until = $employee->get('pinLockedUntil');

        if ($until && $until > $now) {
            return ['result' => self::LOCKED, 'lockedUntil' => $until];
        }

        if ($until) {
            // The lock has run out: a fresh allowance, not one more strike.
            $this->setCounters($employee->getId(), 0, null);
            $employee->set(['pinFailedCount' => 0, 'pinLockedUntil' => null]);
        }

        if (password_verify($this->material($employee, $pin), (string) $employee->get('pinHash'))) {
            if ((int) $employee->get('pinFailedCount') > 0) {
                $this->setCounters($employee->getId(), 0, null);
            }

            return ['result' => self::OK];
        }

        // Atomic +1: parallel guesses must not read the same old value.
        $count = $this->bumpFailures($employee->getId());
        $max = $this->maxAttempts();

        if ($count >= $max) {
            $lockedUntil = gmdate('Y-m-d H:i:s', time() + $this->lockMinutes() * 60);
            $this->setCounters($employee->getId(), $count, $lockedUntil);

            return ['result' => self::LOCKED, 'lockedUntil' => $lockedUntil];
        }

        return ['result' => self::WRONG, 'attemptsLeft' => $max - $count];
    }

    /** "none" | "set" | "locked" for the admin list; never the hash. */
    public function status(Entity $employee): string
    {
        if (!$employee->get('pinHash')) {
            return 'none';
        }

        $until = $employee->get('pinLockedUntil');

        return $until && $until > gmdate('Y-m-d H:i:s') ? 'locked' : 'set';
    }

    private function material(Entity $employee, string $pin): string
    {
        $key = (string) $this->config->get('cryptKey');

        return hash_hmac('sha256', 'nemo-pin:' . $employee->getId() . ':' . $pin, $key);
    }

    private function bumpFailures(string $employeeId): int
    {
        $query = $this->entityManager->getQueryBuilder()
            ->update()
            ->in('Employee')
            ->set(['pinFailedCount:' => 'ADD:(pinFailedCount, 1)'])
            ->where(['id' => $employeeId])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);

        $row = $this->entityManager->getRDBRepository('Employee')
            ->select(['id', 'pinFailedCount'])
            ->where(['id' => $employeeId])
            ->findOne();

        return $row ? (int) $row->get('pinFailedCount') : 1;
    }

    private function setCounters(string $employeeId, int $count, ?string $lockedUntil): void
    {
        $query = $this->entityManager->getQueryBuilder()
            ->update()
            ->in('Employee')
            ->set(['pinFailedCount' => $count, 'pinLockedUntil' => $lockedUntil])
            ->where(['id' => $employeeId])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }
}
