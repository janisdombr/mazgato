<?php

namespace Espo\Custom\Hooks\PromoCode;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Custom\Tools\Loyalty\Promo;
use Espo\Custom\Tools\Validation\Numbers;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * A promo code is stored normalised (A-Z 0-9 "-", 3..24), unique, with a sane value:
 * Percent 1..100, Fixed > 0 EUR; maxUses / perCustomerLimit >= 0 (0 = unlimited); minAmount >= 0; validTo not before validFrom.
 * `usedCount` is server-owned (a readOnly field, moved only by Tools/Loyalty/Promo).
 */
class Validate
{
    public static $order = 5;

    public function __construct(private EntityManager $em)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->isNew() || $entity->isAttributeChanged('code')) {
            $code = Promo::normalize((string) $entity->get('code'));

            if (!Promo::isValidCode($code)) {
                throw new BadRequest('promo_code_invalid: the code is 3..24 characters, A-Z, 0-9 and "-".');
            }

            $entity->set('code', $code);

            $other = $this->em->getRDBRepository('PromoCode')->where(['code' => $code, 'deleted' => false])->findOne();

            if ($other && $other->getId() !== $entity->getId()) {
                throw new Conflict('promo_code_exists');
            }
        }

        if ($entity->isNew() || $entity->isAttributeChanged('value') || $entity->isAttributeChanged('kind')) {
            $kind = (string) ($entity->get('kind') ?: 'Percent');
            $v = $entity->get('value');

            if ($kind === 'Percent') {
                Numbers::assertNumber('value', $v, 1.0, 100.0);
            } else {
                Numbers::assertNumber('value', $v, 0.0, 100000.0, true);
            }
        }

        Numbers::nonNegative($entity, ['minAmount', 'maxUses', 'perCustomerLimit']);

        $from = $entity->get('validFrom');
        $to = $entity->get('validTo');

        if ($from && $to && $to < $from) {
            throw new BadRequest('promo_period_invalid: "valid to" is before "valid from".');
        }
    }
}
