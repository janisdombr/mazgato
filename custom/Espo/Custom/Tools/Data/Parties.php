<?php

namespace Espo\Custom\Tools\Data;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * ONE place for the "who and what car" rule of an order (docs/DATA_RULES.md):
 * every order has a car (Vehicle) and a paying customer with at least a name. A car with no registration
 * gets a TEMPORARY number minted here, `BN-<yymmdd>-<NNN>` (stored normalised as `BN<yymmdd><NNN>`,
 * `Vehicle.plateTemporary` = true) which the operator replaces later with the real one (CarWash/action/setRealPlate).
 * Server is the truth; every UI only suggests.
 */
class Parties
{
    public const PREFIX = 'BN';

    public function __construct(private EntityManager $em)
    {}

    /** Uppercase, strip dashes/spaces/other separators: "LV-1234" -> "LV1234". Same rule as Hooks/Vehicle/NormalizePlate. */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($plate)));
    }

    /** The customer name is mandatory (400 `customer_name_required`). Returns it trimmed. */
    public static function requireName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new BadRequest('customer_name_required');
        }

        return $name;
    }

    /** Next free temporary number of today, e.g. BN260423007 (display form BN-260423-007). */
    public function nextTemporaryPlate(?\DateTimeInterface $now = null): string
    {
        $day = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('ymd');
        $prefix = self::PREFIX . $day;

        // Deleted rows count too (their name keeps the plate as `PLATE~id`), so a number is never handed out twice.
        $st = $this->em->getPDO()->prepare('SELECT name FROM vehicle WHERE name LIKE :p');
        $st->execute([':p' => $prefix . '%']);

        $max = 0;

        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            if (preg_match('/^' . $prefix . '(\d+)/', (string) $name, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $prefix . sprintf('%03d', $max + 1);
    }

    /** "BN260423007" -> "BN-260423-007" (only for a plate that looks like a temporary one). */
    public static function display(string $plate): string
    {
        return preg_match('/^' . self::PREFIX . '(\d{6})(\d{3,})$/', $plate, $m) ? self::PREFIX . '-' . $m[1] . '-' . $m[2] : $plate;
    }

    /**
     * Create a Vehicle with a fresh temporary number for this customer. The number is unique (index over name+deleted);
     * a lost race is retried.
     */
    public function createTemporaryVehicle(?string $customerId, array $extra = []): Entity
    {
        $last = null;

        for ($i = 0; $i < 6; $i++) {
            try {
                return $this->em->createEntity('Vehicle', array_merge($extra, [
                    'name' => $this->nextTemporaryPlate(),
                    'plateTemporary' => true,
                    'customerId' => $customerId,
                ]));
            } catch (\Throwable $e) {
                $last = $e;

                if (stripos($e->getMessage(), 'duplicate') === false && stripos($e->getMessage(), 'unique') === false) {
                    throw $e;
                }
            }
        }

        throw $last;
    }
}
