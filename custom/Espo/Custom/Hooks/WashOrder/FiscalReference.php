<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The CRM is NOT a cash register (docs/FISCAL.md). It only stores the reference of the document that the
 * certified register printed: series, number, device, time. This hook keeps that reference honest:
 *
 *  - `fiscalStatus` is filled only while EMPTY (never overrides what the operator set):
 *      a receipt number is typed       -> Registered
 *      Completed + paid by Invoice     -> NotRequired   (a transfer on an invoice is not a till sale)
 *      Completed + Cash or Card        -> Pending       (to be matched with a till receipt)
 *  - the same (device, series, number) can sit on ONE order only (409): one till receipt, one sale.
 *    A repeat would let one receipt cover two sales, i.e. a hole in the single ledger.
 *  - nothing is generated here: numbers, checksums and times are copied from the register, never invented.
 */
class FiscalReference
{
    public static $order = 30;

    public function __construct(private EntityManager $entityManager)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $no = trim((string) $entity->get('fiscalReceiptNo'));
        $series = trim((string) $entity->get('fiscalReceiptSeries'));
        $device = trim((string) $entity->get('fiscalDeviceId'));

        if ($entity->isAttributeChanged('fiscalReceiptNo') || $entity->isAttributeChanged('fiscalReceiptSeries')
            || $entity->isAttributeChanged('fiscalDeviceId')) {
            $entity->set('fiscalReceiptNo', $no === '' ? null : $no);
            $entity->set('fiscalReceiptSeries', $series === '' ? null : $series);
            $entity->set('fiscalDeviceId', $device === '' ? null : $device);
        }

        if ($no !== '' && ($entity->isNew() || $entity->isAttributeChanged('fiscalReceiptNo')
                || $entity->isAttributeChanged('fiscalReceiptSeries') || $entity->isAttributeChanged('fiscalDeviceId'))) {
            $where = ['fiscalReceiptNo' => $no, 'fiscalReceiptSeries' => $series === '' ? null : $series,
                'fiscalDeviceId' => $device === '' ? null : $device, 'deleted' => false];

            if (!$entity->isNew()) {
                $where['id!='] = $entity->getId();
            }

            if ($this->entityManager->getRDBRepository('WashOrder')->where($where)->count() > 0) {
                throw new Conflict('fiscal_receipt_in_use');
            }
        }

        if (!$entity->get('fiscalStatus')) {
            if ($no !== '') {
                $entity->set('fiscalStatus', 'Registered');
            } elseif ($entity->get('status') === 'Completed') {
                $entity->set('fiscalStatus', $entity->get('paymentMethod') === 'Invoice' ? 'NotRequired' : 'Pending');
            }
        } elseif ($no !== '' && $entity->get('fiscalStatus') === 'Pending') {
            $entity->set('fiscalStatus', 'Registered');
        }
    }
}
