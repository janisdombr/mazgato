<?php

namespace Espo\Custom\Tools\Inventory;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Stocktake (инвентаризация): the physical count per supply becomes the stock. For every counted line the difference
 * against the system quantity (read under a row lock, so a sale in the same second cannot be lost) is written to the
 * journal as a `Stocktake` movement - who, when, why (`reason` is required) - and the supply is set to the count.
 * Lines whose count equals the system quantity are kept on the stocktake (proof it was counted) but write no movement.
 * Stocktakes are created and posted in one step, never edited (`Hooks/Stocktake*`, server only). `clientKey` = retry-safe.
 */
class Stocktakes
{
    public function __construct(private EntityManager $em, private Stock $stock, private User $user)
    {}

    /** @return array{stocktake:Entity, replayed:bool, lines:list<array>} */
    public function run(array $data): array
    {
        $key = isset($data['clientKey']) ? substr(trim((string) $data['clientKey']), 0, 64) : '';

        if ($key !== '' && ($ex = $this->em->getRDBRepository('Stocktake')->where(['clientKey' => $key])->findOne())) {
            return ['stocktake' => $ex, 'replayed' => true, 'lines' => $this->linesOf($ex->getId())];
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw new BadRequest('reason_required');
        }

        $raw = json_decode(json_encode($data['lines'] ?? null), true);

        if (!is_array($raw) || !$raw) {
            throw new BadRequest('stocktake_without_lines');
        }

        $seen = [];
        $lines = [];

        foreach ($raw as $l) {
            $sid = (string) ($l['supplyId'] ?? '');
            $counted = $l['counted'] ?? null;

            if ($sid === '' || !$this->em->getEntityById('Supply', $sid)) {
                throw new BadRequest('supply_not_found');
            }

            if (isset($seen[$sid])) {
                throw new BadRequest('duplicate_supply');
            }

            if (!is_numeric($counted) || (float) $counted < 0) {
                throw new BadRequest('counted_invalid');
            }

            $seen[$sid] = true;
            $lines[] = ['supplyId' => $sid, 'counted' => (float) $counted];
        }

        $tm = $this->em->getTransactionManager();
        $tm->start();
        $out = [];

        try {
            $s = $this->em->getNewEntity('Stocktake');
            $s->set([
                'name' => 'INV-' . date('ymd') . '-' . bin2hex(random_bytes(2)),
                'reason' => mb_substr($reason, 0, 250),
                'status' => 'Posted',
                'postedAt' => gmdate('Y-m-d H:i:s'),
                'postedById' => $this->user->getId(),
                'clientKey' => $key !== '' ? $key : null,
                'linesCount' => count($lines),
            ]);
            $this->em->saveEntity($s, ['allowLedgerWrite' => true]);

            $variance = 0;

            foreach ($lines as $l) {
                $r = $this->stock->move($l['supplyId'], 'Stocktake', ['set' => $l['counted'], 'reason' => $reason, 'stocktakeId' => $s->getId()]);
                $line = $this->em->getNewEntity('StocktakeLine');
                $line->set([
                    'stocktakeId' => $s->getId(),
                    'supplyId' => $l['supplyId'],
                    'expectedQuantity' => $r['before'],
                    'countedQuantity' => $l['counted'],
                    'difference' => $r['delta'],
                ]);
                $this->em->saveEntity($line, ['allowLedgerWrite' => true]);

                if ($r['delta'] != 0.0) {
                    $variance++;
                }

                $out[] = ['supplyId' => $l['supplyId'], 'expected' => $r['before'], 'counted' => $l['counted'], 'difference' => $r['delta']];
            }

            $s->set('varianceCount', $variance);
            $this->em->saveEntity($s, ['allowLedgerWrite' => true]);
            $tm->commit();
        } catch (\Throwable $e) {
            $tm->rollback();

            if ($key !== '' && ($ex = $this->em->getRDBRepository('Stocktake')->where(['clientKey' => $key])->findOne())) {
                return ['stocktake' => $ex, 'replayed' => true, 'lines' => $this->linesOf($ex->getId())];
            }

            throw $e;
        }

        return ['stocktake' => $s, 'replayed' => false, 'lines' => $out];
    }

    private function linesOf(string $id): array
    {
        $out = [];

        foreach ($this->em->getRDBRepository('StocktakeLine')->where(['stocktakeId' => $id])->find() as $l) {
            $out[] = ['supplyId' => $l->get('supplyId'), 'expected' => (float) $l->get('expectedQuantity'), 'counted' => (float) $l->get('countedQuantity'), 'difference' => (float) $l->get('difference')];
        }

        return $out;
    }
}
