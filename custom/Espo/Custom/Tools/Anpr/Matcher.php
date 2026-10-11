<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Plate -> Vehicle. The plate goes through the SAME normalisation as `Hooks/Vehicle/NormalizePlate`
 * (uppercase, no dashes/spaces); then an exact match is a fact and a near miss (one edit, or only
 * characters OCR confuses: O/0 B/8 S/5 I/1 Z/2 D/0 G/6) is only a SUGGESTION the operator confirms.
 * An ambiguous near miss (two different vehicles equally close) suggests nothing.
 */
class Matcher
{
    public const NONE = 'None';
    public const EXACT = 'Exact';
    public const FUZZY = 'Fuzzy';

    /** Characters an OCR mixes up, folded to one representative. */
    private const FOLD = ['O' => '0', 'D' => '0', 'B' => '8', 'S' => '5', 'I' => '1', 'Z' => '2', 'G' => '6'];

    private const MAX_CANDIDATES = 20000;

    public function __construct(private EntityManager $entityManager)
    {}

    public static function normalize(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($plate)) ?? '');
    }

    /** Characters that OCR confuses become one: "AB-1O34" and "AB1034" fold to the same text. */
    public static function fold(string $plate): string
    {
        return strtr(self::normalize($plate), self::FOLD);
    }

    /**
     * @return array{kind: string, vehicle: ?Entity, suggested: ?string}
     */
    public function match(string $plate): array
    {
        $plate = self::normalize($plate);

        if ($plate === '') {
            return ['kind' => self::NONE, 'vehicle' => null, 'suggested' => null];
        }

        $exact = $this->entityManager->getRDBRepository('Vehicle')
            ->where(['name' => $plate, 'deleted' => false])
            ->findOne();

        if ($exact) {
            return ['kind' => self::EXACT, 'vehicle' => $exact, 'suggested' => null];
        }

        if (strlen($plate) < 4) {
            return ['kind' => self::NONE, 'vehicle' => null, 'suggested' => null];
        }

        $folded = self::fold($plate);
        $best = null;
        $bestDistance = 99;
        $ambiguous = false;

        $rows = $this->entityManager->getRDBRepository('Vehicle')
            ->select(['id', 'name'])
            ->where(['deleted' => false])
            ->limit(0, self::MAX_CANDIDATES)
            ->find();

        foreach ($rows as $row) {
            $name = (string) $row->get('name');

            if (abs(strlen($name) - strlen($plate)) > 1) {
                continue;
            }

            $d = self::fold($name) === $folded ? 0 : levenshtein($plate, $name);

            if ($d > 1) {
                continue;
            }

            if ($d < $bestDistance) {
                $best = $row;
                $bestDistance = $d;
                $ambiguous = false;
            } elseif ($d === $bestDistance) {
                $ambiguous = true;
            }
        }

        if (!$best || $ambiguous) {
            return ['kind' => self::NONE, 'vehicle' => null, 'suggested' => null];
        }

        return ['kind' => self::FUZZY, 'vehicle' => $this->entityManager->getEntityById('Vehicle', $best->getId()), 'suggested' => (string) $best->get('name')];
    }

    /** The car's order that is on the board or waiting for the ramp (status New / In Progress). */
    public function activeOrder(?Entity $vehicle): ?Entity
    {
        if (!$vehicle) {
            return null;
        }

        return $this->entityManager->getRDBRepository('WashOrder')
            ->where(['vehicleId' => $vehicle->getId(), 'status' => ['New', 'In Progress'], 'deleted' => false])
            ->order('orderDateTime', 'DESC')
            ->findOne();
    }
}
