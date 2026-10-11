<?php

namespace Espo\Custom\Tools\Booking;

use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Custom\Tools\Notify\Notifier;
use Espo\ORM\EntityManager;

/**
 * Housekeeping for reservations (`run($orderId)` limits it to one order - used by tests):
 *  - reminder `bookingReminderHours` before the start (once: reminderSentAt);
 *  - no-show: a Reserved order still not started `bookingNoShowGraceMinutes` after its time is
 *    flagged noShow and Cancelled, which releases the bay.
 */
class Maintenance
{
    public function __construct(
        private EntityManager $em,
        private Config $config,
        private Notifier $notifier,
        private Planner $planner,
        private Modules $modules
    ) {}

    /** @return array{reminded:int,noShow:int} */
    public function run(?string $onlyOrderId = null): array
    {
        $out = ['reminded' => 0, 'noShow' => 0];

        if (!$this->modules->moduleEnabled(Modules::BOOKING)) {
            return $out;
        }

        $now = $this->planner->now();
        $hours = (int) ($this->config->get('bookingReminderHours') ?? 3);

        if ($hours > 0) {
            $orders = $this->em->getRDBRepository('WashOrder')->where(($onlyOrderId ? ['id' => $onlyOrderId] : []) + [
                'status' => 'Reserved',
                'deleted' => false,
                'reminderSentAt' => null,
                'orderDateTime>' => $now->format('Y-m-d H:i:s'),
                'orderDateTime<=' => $now->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s'),
            ])->find();

            foreach ($orders as $o) {
                $this->em->getQueryExecutor()->execute(
                    $this->em->getQueryBuilder()->update()->in('WashOrder')
                        ->set(['reminderSentAt' => $now->format('Y-m-d H:i:s')])
                        ->where(['id' => $o->getId()])->build()
                );
                $this->notifier->notify($o, 'Reminder');
                $out['reminded']++;
            }
        }

        $grace = (int) ($this->config->get('bookingNoShowGraceMinutes') ?? 30);

        if ($grace > 0 && !$onlyOrderId) {
            $late = $this->em->getRDBRepository('WashOrder')->where([
                'status' => 'Reserved',
                'deleted' => false,
                'orderDateTime<' => $now->modify('-' . $grace . ' minutes')->format('Y-m-d H:i:s'),
            ])->find();

            foreach ($late as $o) {
                $this->markNoShow($o);
                $out['noShow']++;
            }
        }

        return $out;
    }

    public function markNoShow(\Espo\ORM\Entity $o): void
    {
        $o->set(['noShow' => true, 'status' => 'Cancelled']);
        $this->em->saveEntity($o, ['skipReserveGuard' => true, 'skipNotify' => true]);
    }
}
