<?php

namespace Espo\Custom\Tools\Validation;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;

/**
 * One definition of "a sane amount / quantity" for every custom entity that carries money or stock.
 * A negative price, a share of 250 %, a quantity of INF (JSON `1e999` decodes to INF in PHP) used to be
 * stored as typed; the order total then went negative or silently wrong. The answer is always a 400
 * that names the field, so the operator (or an integration) can see what to fix.
 */
final class Numbers
{
    /** True for a real, finite number (int, float or a numeric string). */
    public static function isFinite(mixed $v): bool
    {
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) && is_finite((float) $v);
    }

    /** @throws BadRequest */
    public static function assertNumber(string $field, mixed $v, float $min = 0.0, ?float $max = null, bool $minExclusive = false): void
    {
        if (!self::isFinite($v)) {
            throw new BadRequest("invalid_number: '$field' must be a finite number.");
        }

        $f = (float) $v;

        if ($f < $min || ($minExclusive && $f <= $min)) {
            throw new BadRequest("invalid_number: '$field' must be " . ($minExclusive ? 'greater than ' : 'at least ') . self::fmt($min) . '.');
        }

        if ($max !== null && $f > $max) {
            throw new BadRequest("invalid_number: '$field' must be at most " . self::fmt($max) . '.');
        }
    }

    /**
     * Every listed field that is set (not null / '') and was changed — or the entity is new — must be a finite number >= 0.
     * "Only when changed" keeps an unrelated save of an old record (e.g. stock that consumption took below zero)
     * from failing on a value the user did not touch.
     *
     * @param string[] $fields
     * @throws BadRequest
     */
    public static function nonNegative(Entity $e, array $fields): void
    {
        foreach ($fields as $f) {
            $v = $e->get($f);

            if ($v === null || $v === '') {
                continue;
            }

            if ($e->isNew() || $e->isAttributeChanged($f)) {
                self::assertNumber($f, $v);
            }
        }
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    }
}
