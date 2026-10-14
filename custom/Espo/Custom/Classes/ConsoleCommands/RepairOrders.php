<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Data\Parties;
use Espo\ORM\EntityManager;

/**
 * One-off, idempotent repair of orders that predate the rule "an order always has a car and a payer"
 * (docs/DATA_RULES.md). Money is never touched and nothing is deleted:
 *  - an order with no customer is tied to ONE shared customer "Без имени (восстановлено)" (name in the installation
 *    language; found again on a re-run by its marker in the description);
 *  - an order with no car gets its own temporary number BN-<yymmdd>-<NNN> (car belongs to the order's customer);
 *  - the order's description gets a line saying what was repaired.
 * A subscription sale needs no car (only a customer). Writes use direct queries: no hook runs, no message is sent.
 *
 *     bin/command repair-orders --dry-run     # count and list, change nothing (default)
 *     bin/command repair-orders --commit
 */
class RepairOrders implements Command
{
    private const MARKER = '[repair-orders]';

    private const NAMES = [
        'lv_LV' => 'Bez vārda (atjaunots)',
        'ru_RU' => 'Без имени (восстановлено)',
        'en_US' => 'Unnamed (restored)',
        'lt_LT' => 'Be vardo (atkurta)',
    ];

    public function __construct(private EntityManager $em, private Config $config, private Parties $parties)
    {}

    public function run(Params $params, IO $io): void
    {
        $commit = $params->hasFlag('commit') && !$params->hasFlag('dryRun');

        $orders = $this->em->getRDBRepository('WashOrder')->where(['deleted' => false])->find();
        $noCustomer = [];
        $noVehicle = [];

        foreach ($orders as $o) {
            if (!$o->get('customerId')) {
                $noCustomer[] = $o->getId();
            }

            if (!$o->get('vehicleId') && !$o->get('subscriptionSale')) {
                $noVehicle[] = $o->getId();
            }
        }

        $ids = array_values(array_unique(array_merge($noCustomer, $noVehicle)));
        $io->writeLine(count($noCustomer) . ' order(s) without a customer, ' . count($noVehicle) . ' without a car.');

        if (!$ids) {
            $io->writeLine('Nothing to repair.');

            return;
        }

        if (!$commit) {
            foreach ($ids as $id) {
                $o = $this->em->getEntityById('WashOrder', $id);
                $io->writeLine('  ' . $o->get('name') . ' [' . $o->get('status') . '] ' . ($o->get('customerId') ? '' : 'no customer ') . (in_array($id, $noVehicle, true) ? 'no car' : ''));
            }

            $io->writeLine('Dry run: nothing changed. Use --commit to repair.');

            return;
        }

        $shared = null;
        $today = date('Y-m-d');

        foreach ($ids as $id) {
            $o = $this->em->getEntityById('WashOrder', $id);
            $set = [];
            $notes = [];

            if (!$o->get('customerId')) {
                $shared = $shared ?: $this->sharedCustomer();
                $set['customerId'] = $shared->getId();
                $notes[] = 'customer';
            }

            if (in_array($id, $noVehicle, true)) {
                $customerId = $set['customerId'] ?? $o->get('customerId');
                $set['vehicleId'] = $this->parties->createTemporaryVehicle($customerId)->getId();
                $notes[] = 'car';
            }

            $desc = trim((string) $o->get('description'));
            $set['description'] = trim($desc . "\n" . self::MARKER . " $today: " . implode(' + ', $notes) . ' restored');

            $this->em->getQueryExecutor()->execute(
                $this->em->getQueryBuilder()->update()->in('WashOrder')->set($set)->where(['id' => $id])->build()
            );
        }

        $io->writeLine('Repaired ' . count($ids) . ' order(s).');
    }

    private function sharedCustomer()
    {
        $found = $this->em->getRDBRepository('Customer')
            ->where(['description*' => '%' . self::MARKER . '%', 'deleted' => false])->findOne();

        if ($found) {
            return $found;
        }

        $lang = (string) $this->config->get('language', 'lv_LV');

        return $this->em->createEntity('Customer', [
            'name' => self::NAMES[$lang] ?? self::NAMES['en_US'],
            'description' => self::MARKER . ' Orders that had no customer were tied here. Find the real payer and move them.',
            'allowEmail' => false,
        ]);
    }
}
