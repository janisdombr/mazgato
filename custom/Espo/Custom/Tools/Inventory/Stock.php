<?php

namespace Espo\Custom\Tools\Inventory;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;

/**
 * The ONE place stock changes (CLAUDE.md "Inventory"): every change of `Supply.stockQuantity` goes through
 * `move()`, which, inside a transaction and with the supply row locked,
 *   - changes the quantity with one atomic UPDATE (never read-modify-write from a stale entity),
 *   - refreshes the reorder flag,
 *   - appends one `SupplyMovement` row (kind Receipt / Consumption / Stocktake / Manual): before, after, delta, who, why.
 * The journal is append-only (`Hooks/SupplyMovement/Immutable`).
 *
 * Cost rule (documented in docs/INVENTORY.md): a RECEIPT re-prices the supply as the WEIGHTED AVERAGE of what is in
 * stock and what came in - (qty * cost + in * price) / (qty + in). Empty / negative stock or no known cost takes the
 * receipt price; a receipt line without a price leaves the cost alone. Consumption and stocktakes never change the cost.
 */
class Stock
{
    public function __construct(private EntityManager $em, private Config $config)
    {}

    public function currency(): string
    {
        return (string) ($this->config->get('baseCurrency') ?: $this->config->get('defaultCurrency') ?: 'EUR');
    }

    /**
     * @param array{delta?:float, set?:float, price?:?float, reason?:?string, washOrderId?:?string, receiptId?:?string, stocktakeId?:?string} $o
     * @return array{before:float, after:float, delta:float, cost:float, movementId:?string}
     * @throws BadRequest
     */
    public function move(string $supplyId, string $kind, array $o): array
    {
        $pdo = $this->em->getPDO();
        $tm = $this->em->getTransactionManager();
        $tm->start();

        try {
            $st = $pdo->prepare('SELECT stock_quantity, unit_cost, name FROM supply WHERE id = ? AND deleted = 0 FOR UPDATE');
            $st->execute([$supplyId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                throw new BadRequest('supply_not_found');
            }

            $before = (float) $row['stock_quantity'];
            $oldCost = (float) $row['unit_cost'];
            $delta = array_key_exists('set', $o) ? (float) $o['set'] - $before : (float) ($o['delta'] ?? 0);
            $after = round($before + $delta, 4);
            $delta = round($delta, 4);
            $cost = $oldCost;
            $price = $o['price'] ?? null;

            if ($price !== null && $price > 0 && $delta > 0) {
                $cost = ($before > 0 && $oldCost > 0)
                    ? round(($before * $oldCost + $delta * $price) / ($before + $delta), 4)
                    : round((float) $price, 4);
            }

            // Reorder flag first: in MySQL a later assignment sees the earlier one's NEW value.
            $up = $pdo->prepare(
                'UPDATE supply SET needs_reorder = (reorder_level > 0 AND ? <= reorder_level), unit_cost = ?, ' .
                'stock_quantity = ?, modified_at = ? WHERE id = ?'
            );
            $up->execute([$after, $cost, $after, gmdate('Y-m-d H:i:s'), $supplyId]);

            $movementId = null;

            if ($delta != 0.0) {
                $m = $this->em->getNewEntity('SupplyMovement');
                $m->set([
                    'name' => $kind . ' ' . ($delta > 0 ? '+' : '') . rtrim(rtrim(number_format($delta, 4, '.', ''), '0'), '.'),
                    'kind' => $kind,
                    'supplyId' => $supplyId,
                    'delta' => $delta,
                    'quantityBefore' => $before,
                    'quantityAfter' => $after,
                    'unitCost' => $cost,
                    'unitCostCurrency' => $this->currency(),
                    'reason' => isset($o['reason']) ? mb_substr((string) $o['reason'], 0, 250) : null,
                    'occurredAt' => gmdate('Y-m-d H:i:s'),
                    'washOrderId' => $o['washOrderId'] ?? null,
                    'receiptId' => $o['receiptId'] ?? null,
                    'stocktakeId' => $o['stocktakeId'] ?? null,
                ]);
                $this->em->saveEntity($m, ['allowLedgerWrite' => true]);
                $movementId = $m->getId();
            }

            $tm->commit();
        } catch (\Throwable $e) {
            $tm->rollback();

            throw $e;
        }

        return ['before' => $before, 'after' => $after, 'delta' => $delta, 'cost' => $cost, 'movementId' => $movementId];
    }

    /** Stock used by an order (DeductSupplies). */
    public function consume(string $supplyId, float $quantity, ?string $orderId, ?string $reason = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        $this->move($supplyId, 'Consumption', ['delta' => -$quantity, 'washOrderId' => $orderId, 'reason' => $reason]);
    }

    public function today(): string
    {
        $tz = new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC');

        return (new \DateTime('now', $tz))->format('Y-m-d');
    }
}
