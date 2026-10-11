<?php

namespace Espo\Custom\Hooks\SupplyReceiptLine;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Lines of a Draft receipt are free to edit; lines of a Posted one are locked. `lineTotal` = quantity x price.
 * The receipt's `total` follows the lines.
 */
class Rules
{
    public static $order = 1;

    public function __construct(private EntityManager $em)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $qty = (float) $entity->get('quantity');

        if (!\Espo\Custom\Tools\Validation\Numbers::isFinite($entity->get('quantity'))) {
            throw new BadRequest('quantity_must_be_positive');
        }

        if ($entity->get('unitPrice') !== null && !\Espo\Custom\Tools\Validation\Numbers::isFinite($entity->get('unitPrice'))) {
            throw new BadRequest('price_invalid');
        }

        if ($qty <= 0) {
            throw new BadRequest('quantity_must_be_positive');
        }

        if ($entity->get('unitPrice') !== null && (float) $entity->get('unitPrice') < 0) {
            throw new BadRequest('price_invalid');
        }

        if (empty($options['allowLedgerWrite'])) {
            $this->assertDraft((string) $entity->get('receiptId'));

            if (!$entity->isNew() && $entity->isAttributeChanged('receiptId')) {
                $this->assertDraft((string) $entity->getFetched('receiptId'));
            }
        }

        $entity->set('lineTotal', round($qty * (float) ($entity->get('unitPrice') ?? 0), 4));

        if ($entity->get('supplyId') && ($entity->isNew() || $entity->isAttributeChanged('supplyId') || !$entity->get('name'))) {
            $supply = $this->em->getEntityById('Supply', (string) $entity->get('supplyId'));
            $entity->set('name', $supply ? $supply->get('name') : '-');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            $this->assertDraft((string) $entity->get('receiptId'));
        }
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            $this->recalc((string) $entity->get('receiptId'));
        }
    }

    public function afterRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite'])) {
            $this->recalc((string) $entity->get('receiptId'));
        }
    }

    private function assertDraft(string $receiptId): void
    {
        $r = $receiptId !== '' ? $this->em->getEntityById('SupplyReceipt', $receiptId) : null;

        if (!$r) {
            throw new BadRequest('receipt_required');
        }

        if ($r->get('status') === 'Posted') {
            throw new Forbidden('receipt_posted_locked');
        }
    }

    private function recalc(string $receiptId): void
    {
        if ($receiptId === '') {
            return;
        }

        $st = $this->em->getPDO()->prepare(
            'UPDATE supply_receipt SET total = COALESCE((SELECT SUM(line_total) FROM supply_receipt_line ' .
            'WHERE receipt_id = ? AND deleted = 0), 0) WHERE id = ?'
        );
        $st->execute([$receiptId, $receiptId]);
    }
}
