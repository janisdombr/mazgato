<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Inventory\Receipts;
use Espo\Custom\Tools\Inventory\Stocktakes;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Inventory/action/*  (rules live in Tools/Inventory; this is only ACL + shape)
 *
 *  sheet        {}                                    -> every supply with its system quantity (the stocktake sheet / receipt picker)
 *  receive      { supplier?, invoiceNumber?, receiptDate?, note?, lines:[{supplyId, quantity, unitPrice?}], clientKey? }
 *               -> creates AND posts a goods receipt (stock up, weighted-average cost, journal rows)
 *  postReceipt  { id }                                -> posts an existing Draft receipt (idempotent: a second call does nothing)
 *  stocktake    { reason, lines:[{supplyId, counted}], clientKey? } -> sets stock to the count, journals the differences
 *
 * ACL: sheet = read Supply; receive = create SupplyReceipt + read Supply; postReceipt = edit SupplyReceipt;
 * stocktake = create Stocktake + edit Supply. A tablet device key opens none of it.
 */
class Inventory
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private Receipts $receipts,
        private Stocktakes $stocktakes,
        DeviceGuard $deviceGuard
    ) {
        $deviceGuard->denyDevice($user);
    }

    public function postActionSheet(Request $request): stdClass
    {
        if (!$this->acl->checkScope('Supply', 'read')) {
            throw new Forbidden();
        }

        $list = [];

        foreach ($this->entityManager->getRDBRepository('Supply')->order('name')->find() as $s) {
            $list[] = (object) [
                'id' => $s->getId(),
                'name' => $s->get('name'),
                'unit' => $s->get('unit'),
                'stockQuantity' => (float) $s->get('stockQuantity'),
                'unitCost' => (float) $s->get('unitCost'),
            ];
        }

        return (object) ['list' => $list];
    }

    public function postActionReceive(Request $request): stdClass
    {
        if (!$this->acl->checkScope('SupplyReceipt', 'create') || !$this->acl->checkScope('Supply', 'read')) {
            throw new Forbidden();
        }

        $r = $this->receipts->receive($this->body($request));

        return $this->receiptAnswer($r);
    }

    public function postActionPostReceipt(Request $request): stdClass
    {
        $id = (string) ($this->body($request)['id'] ?? '');

        if ($id === '') {
            throw new BadRequest('id_required');
        }

        $receipt = $this->entityManager->getEntityById('SupplyReceipt', $id);

        if ($receipt && !$this->acl->checkEntity($receipt, 'edit')) {
            throw new Forbidden();
        }

        return $this->receiptAnswer($this->receipts->post($id));
    }

    public function postActionStocktake(Request $request): stdClass
    {
        if (!$this->acl->checkScope('Stocktake', 'create') || !$this->acl->checkScope('Supply', 'edit')) {
            throw new Forbidden();
        }

        $r = $this->stocktakes->run($this->body($request));
        /** @var Entity $s */
        $s = $r['stocktake'];

        return (object) [
            'ok' => true,
            'replayed' => $r['replayed'],
            'id' => $s->getId(),
            'name' => $s->get('name'),
            'linesCount' => (int) $s->get('linesCount'),
            'varianceCount' => (int) $s->get('varianceCount'),
            'lines' => array_map(fn ($l) => (object) $l, $r['lines']),
        ];
    }

    private function receiptAnswer(array $r): stdClass
    {
        /** @var Entity $e */
        $e = $r['receipt'];

        return (object) [
            'ok' => true,
            'replayed' => $r['replayed'],
            'id' => $e->getId(),
            'name' => $e->get('name'),
            'status' => $e->get('status'),
            'total' => (float) $e->get('total'),
        ];
    }

    private function body(Request $request): array
    {
        return (array) json_decode(json_encode($request->getParsedBody() ?: new stdClass()), true);
    }
}
