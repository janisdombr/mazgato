<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Fill order defaults so a manually created order needs minimal input:
 *  - auto-generate the number if left empty;
 *  - copy the "corporate" and "has contract" flags from the customer — both live on
 *    the Customer and are independent of each other (there are contracts with private
 *    individuals);
 *  - a contract customer is billed by invoice: paymentMethod = Invoice and
 *    paymentStatus = Invoiced (the money is owed, not collected).
 * Runs before CalculateAmounts (which needs finalPrice only).
 */
class SetDefaults
{
    public static $order = 7;

    public function __construct(private EntityManager $entityManager)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!$entity->get('name')) {
            $entity->set('name', 'ORD-' . date('ymd-His'));
        }

        $customerId = $entity->get('customerId');

        if ($customerId && $entity->isAttributeChanged('customerId')) {
            $customer = $this->entityManager->getEntityById('Customer', $customerId);

            if ($customer) {
                $entity->set('isCorporate', (bool) $customer->get('isCorporate'));
                $entity->set('hasContract', (bool) $customer->get('hasContract'));
            }
        }

        // Contract work is billed by invoice: nothing is collected at the counter, but the
        // money is NOT in either — the order starts as "invoice issued" and becomes Paid
        // only when the payment actually arrives. Only on create: a later manual change of
        // paymentMethod/paymentStatus is never overridden.
        // `isAttributeChanged` cannot tell "the operator chose Cash" from "the enum
        // default Cash was auto-populated" on a NEW entity — both count as changed. The
        // intake endpoint therefore says so explicitly via the `explicitPaymentMethod`
        // save option; without it, an untouched (empty or default-Cash) method on a
        // contract order becomes Invoice.
        if (
            $entity->isNew()
            && $entity->get('hasContract')
            && empty($options['explicitPaymentMethod'])
            && (!$entity->get('paymentMethod') || $entity->get('paymentMethod') === 'Cash')
        ) {
            $entity->set('paymentMethod', 'Invoice');
        }

        // The status follows the RESOLVED method, not the contract flag: the intake form
        // now offers the method explicitly, so a contract customer may still pay cash at
        // the counter (stays Unpaid until "€ Paid") and a walk-in may ask for an invoice.
        if ($entity->isNew() && $entity->get('paymentMethod') === 'Invoice') {
            $entity->set('paymentStatus', 'Invoiced');
        }
    }
}
