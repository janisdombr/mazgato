<?php

namespace Espo\Custom\Tools\Inventory;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Goods receipt (приёмка): supplier + invoice number + date + lines (supply, quantity, unit price).
 * POSTING raises the stock, exactly once: the Draft -> Posted flip is one atomic UPDATE ... WHERE status = 'Draft'
 * (a second post, a retried request or a concurrent one finds it already Posted and does nothing: `replayed`).
 * A posted receipt and its lines can no longer be edited or removed (Hooks/SupplyReceipt*); a mistake is fixed by a
 * stocktake, which is visible in the journal.
 */
class Receipts
{
    public function __construct(private EntityManager $em, private Stock $stock, private User $user)
    {}

    /**
     * Create + post in one go (the "Приёмка" dialog). `clientKey` makes a retry answer with the same receipt.
     *
     * @return array{receipt:Entity, replayed:bool}
     */
    public function receive(array $data): array
    {
        $key = isset($data['clientKey']) ? substr(trim((string) $data['clientKey']), 0, 64) : '';

        if ($key !== '') {
            $existing = $this->em->getRDBRepository('SupplyReceipt')->where(['clientKey' => $key])->findOne();

            if ($existing) {
                return $this->post($existing->getId());
            }
        }

        $lines = $this->cleanLines($data['lines'] ?? null);
        $tm = $this->em->getTransactionManager();
        $tm->start();

        try {
            $r = $this->em->getNewEntity('SupplyReceipt');
            $r->set([
                'supplier' => $this->str($data['supplier'] ?? null, 120),
                'invoiceNumber' => $this->str($data['invoiceNumber'] ?? null, 60),
                'receiptDate' => $this->date($data['receiptDate'] ?? null),
                'note' => $this->str($data['note'] ?? null, 2000),
                'clientKey' => $key !== '' ? $key : null,
            ]);
            $this->em->saveEntity($r, ['allowLedgerWrite' => true]);
            $this->saveLines($r, $lines);
            $tm->commit();
        } catch (\Throwable $e) {
            $tm->rollback();

            // Two identical requests raced on the unique clientKey: the loser answers with the winner's receipt.
            if ($key !== '' && ($ex = $this->em->getRDBRepository('SupplyReceipt')->where(['clientKey' => $key])->findOne())) {
                return $this->post($ex->getId());
            }

            throw $e;
        }

        return $this->post($r->getId());
    }

    /** @return array{receipt:Entity, replayed:bool} */
    public function post(string $id): array
    {
        $r = $this->em->getEntityById('SupplyReceipt', $id);

        if (!$r) {
            throw new NotFound('receipt_not_found');
        }

        if ($r->get('status') === 'Posted') {
            return ['receipt' => $r, 'replayed' => true];
        }

        $lines = $this->em->getRDBRepository('SupplyReceiptLine')->where(['receiptId' => $id])->order('createdAt')->find();

        if (count($lines) === 0) {
            throw new BadRequest('receipt_without_lines');
        }

        $pdo = $this->em->getPDO();
        $tm = $this->em->getTransactionManager();
        $tm->start();

        try {
            $claim = $pdo->prepare(
                "UPDATE supply_receipt SET status = 'Posted', posted_at = ?, posted_by_id = ? " .
                "WHERE id = ? AND status = 'Draft' AND deleted = 0"
            );
            $claim->execute([gmdate('Y-m-d H:i:s'), $this->user->getId(), $id]);

            if ($claim->rowCount() === 0) {
                $tm->rollback();

                return ['receipt' => $this->em->getEntityById('SupplyReceipt', $id), 'replayed' => true];
            }

            $label = trim(($r->get('supplier') ?: '') . ' ' . ($r->get('invoiceNumber') ?: ''));

            foreach ($lines as $line) {
                $this->stock->move((string) $line->get('supplyId'), 'Receipt', [
                    'delta' => (float) $line->get('quantity'),
                    'price' => $line->get('unitPrice') !== null ? (float) $line->get('unitPrice') : null,
                    'receiptId' => $id,
                    'reason' => $label !== '' ? $label : null,
                ]);
            }

            $this->recalcTotal($id);
            $tm->commit();
        } catch (\Throwable $e) {
            $tm->rollback();

            throw $e;
        }

        return ['receipt' => $this->em->getEntityById('SupplyReceipt', $id), 'replayed' => false];
    }

    public function recalcTotal(string $receiptId): void
    {
        $st = $this->em->getPDO()->prepare(
            'UPDATE supply_receipt SET total = COALESCE((SELECT SUM(line_total) FROM supply_receipt_line ' .
            'WHERE receipt_id = ? AND deleted = 0), 0), total_currency = ? WHERE id = ?'
        );
        $st->execute([$receiptId, $this->stock->currency(), $receiptId]);
    }

    private function saveLines(Entity $r, array $lines): void
    {
        foreach ($lines as $l) {
            $line = $this->em->getNewEntity('SupplyReceiptLine');
            $line->set([
                'receiptId' => $r->getId(),
                'supplyId' => $l['supplyId'],
                'quantity' => $l['quantity'],
                'unitPrice' => $l['unitPrice'],
                'unitPriceCurrency' => $this->stock->currency(),
            ]);
            $this->em->saveEntity($line, ['allowLedgerWrite' => true]);
        }

        $this->recalcTotal($r->getId());
    }

    /** @return list<array{supplyId:string, quantity:float, unitPrice:?float}> */
    private function cleanLines($raw): array
    {
        $raw = json_decode(json_encode($raw), true);

        if (!is_array($raw) || !$raw) {
            throw new BadRequest('receipt_without_lines');
        }

        $out = [];

        foreach ($raw as $l) {
            $sid = (string) ($l['supplyId'] ?? '');
            $qty = $l['quantity'] ?? null;
            $price = $l['unitPrice'] ?? null;

            if ($sid === '' || !$this->em->getEntityById('Supply', $sid)) {
                throw new BadRequest('supply_not_found');
            }

            if (!is_numeric($qty) || (float) $qty <= 0) {
                throw new BadRequest('quantity_must_be_positive');
            }

            if ($price !== null && $price !== '' && (!is_numeric($price) || (float) $price < 0)) {
                throw new BadRequest('price_invalid');
            }

            $out[] = ['supplyId' => $sid, 'quantity' => (float) $qty, 'unitPrice' => ($price === null || $price === '') ? null : (float) $price];
        }

        return $out;
    }

    private function str($v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function date($v): string
    {
        $v = trim((string) ($v ?? ''));

        if ($v === '') {
            return $this->stock->today();
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) {
            throw new BadRequest('date_invalid');
        }

        return $v;
    }
}
