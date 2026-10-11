<?php

namespace Espo\Custom\Tools\Anpr;

use Espo\Core\InjectableFactory;
use Espo\Custom\Controllers\CarWash;
use Espo\Custom\Tools\Assistant\InternalRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The bridge from a recognised plate to the EXISTING intake logic. Nothing about orders is
 * decided here: the lookup is `CarWash::postActionIntake` and the creation is
 * `CarWash::postActionCreateOrder` (customer + vehicle + order in one go, "one car, one ramp"
 * hook included), called through `InternalRequest` exactly like the assistant's tools do.
 */
class Orders
{
    /** What createOrder accepts from a plate-event confirmation (nothing else is passed on). */
    private const PASS = [
        'customerId', 'customerName', 'phone', 'email', 'isCorporate', 'hasContract', 'brand', 'model',
        'serviceId', 'addonIds', 'addonPrices', 'paymentMethod', 'pvnEnabled', 'finalPrice', 'driverId', 'driverName',
        'driverPhone', 'roleAssignments', 'workAssignments', 'washerId', 'dryerId', 'salonId', 'status',
    ];

    public function __construct(
        private InjectableFactory $factory,
        private EntityManager $entityManager
    ) {}

    /** The intake lookup of a plate (customer card, history, last order). */
    public function lookup(string $plate): \stdClass
    {
        return $this->cw()->postActionIntake(new InternalRequest(['plate' => $plate]));
    }

    /**
     * What to offer the operator for a known car: the services of its last orders (this car
     * first, then the customer's other cars), newest first, de-duplicated, at most four.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function recentServices(?string $vehicleId, ?string $customerId): array
    {
        $out = [];
        $seen = [];

        foreach ([['vehicleId', $vehicleId], ['customerId', $customerId]] as [$col, $id]) {
            if (!$id) {
                continue;
            }

            $orders = $this->entityManager->getRDBRepository('WashOrder')
                ->where([$col => $id, 'serviceId!=' => null, 'deleted' => false])
                ->order('orderDateTime', 'DESC')
                ->limit(0, 20)
                ->find();

            foreach ($orders as $o) {
                $sid = (string) $o->get('serviceId');

                if ($sid !== '' && !isset($seen[$sid]) && count($out) < 4) {
                    $seen[$sid] = true;
                    $out[] = ['id' => $sid, 'name' => (string) $o->get('serviceName')];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fields from the operator (whitelisted)
     * @return \stdClass the createOrder answer (orderId, orderName, customerId, vehicleId ...)
     */
    public function create(string $plate, array $fields): \stdClass
    {
        $data = ['plate' => $plate];

        foreach (self::PASS as $k) {
            if (array_key_exists($k, $fields) && $fields[$k] !== null && $fields[$k] !== '') {
                $data[$k] = $fields[$k];
            }
        }

        // JSON objects/arrays arrive as stdClass/array; createOrder reads them as objects.
        $data = json_decode(json_encode($data), false);

        return $this->cw()->postActionCreateOrder(new InternalRequest((array) $data));
    }

    private function cw(): CarWash
    {
        return $this->factory->create(CarWash::class);
    }
}
