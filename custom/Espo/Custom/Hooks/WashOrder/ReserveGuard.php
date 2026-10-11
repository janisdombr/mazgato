<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Custom\Tools\Booking\Planner;
use Espo\ORM\Entity;

/**
 * Rules for a Reserved order, whoever creates it (operator, e-mail funnel, public booking):
 *  - a booking without data is rubbish: it needs a customer, a vehicle (plate) and an arrival time
 *    (`orderDateTime`), otherwise 400 `reserve_missing_*` naming what is absent;
 *  - the start may not be in the past (a 15-minute grace keeps "reserve for now" working);
 *  - the slot must have a free bay: otherwise 409 Conflict;
 *  - `plannedMinutes` (service + add-ons), `post` (the bay) and the `manageToken` (cancel /
 *    reschedule link) are filled in.
 * Walk-in orders (New / In Progress) are never refused here — only counted by the Planner.
 * Working hours are NOT enforced for staff (an operator may squeeze a car in after hours);
 * the public booking checks them itself.
 */
class ReserveGuard
{
    public static $order = 4;

    public function __construct(private Planner $planner) {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipReserveGuard']) || $entity->get('status') !== 'Reserved') {
            return;
        }

        $this->requireData($entity);

        if (!$entity->get('manageToken')) {
            $entity->set('manageToken', bin2hex(random_bytes(12)));
        }

        $changed = $entity->isNew()
            || $entity->isAttributeChanged('orderDateTime')
            || $entity->isAttributeChanged('status')
            || $entity->isAttributeChanged('serviceId');

        if (!$changed || !$entity->get('orderDateTime')) {
            return;
        }

        $start = new \DateTimeImmutable((string) $entity->get('orderDateTime'), new \DateTimeZone('UTC'));
        $now = $this->planner->now();

        if (($entity->isNew() || $entity->isAttributeChanged('orderDateTime'))
            && $start < $now->modify('-' . $this->planner->pastGrace() . ' minutes')
        ) {
            throw new BadRequest('A reservation cannot start in the past.');
        }

        $addons = $entity->get('addonsIds');
        $addons = is_array($addons) ? $addons : [];

        if (!$entity->get('plannedMinutes') || $entity->isAttributeChanged('serviceId')) {
            $entity->set('plannedMinutes', $this->planner->duration($entity->get('serviceId'), $addons));
        }

        $r = $this->planner->check(
            $start,
            (int) $entity->get('plannedMinutes'),
            $entity->isNew() ? null : $entity->getId(),
            $start > $now->modify('+' . $this->planner->pastGrace() . ' minutes')
        );

        if (!$r['ok']) {
            throw new Conflict('No free bay for this time (' . $r['reason'] . ').');
        }

        if (!$entity->get('post') || $entity->isAttributeChanged('orderDateTime')) {
            $entity->set('post', $r['post']);
        }
    }

    /**
     * Checked when the order is created / becomes Reserved, and again when one of the three is edited
     * (so a booking cannot be emptied afterwards); an unrelated save of an old record is not refused.
     */
    private function requireData(Entity $entity): void
    {
        $touched = $entity->isNew() || $entity->isAttributeChanged('status')
            || $entity->isAttributeChanged('customerId') || $entity->isAttributeChanged('vehicleId')
            || $entity->isAttributeChanged('orderDateTime');

        if (!$touched) {
            return;
        }

        if (!$entity->get('customerId')) {
            throw new BadRequest('reserve_missing_customer: a reservation needs a customer (or a phone / name to create one).');
        }

        if (!$entity->get('vehicleId')) {
            throw new BadRequest('reserve_missing_vehicle: a reservation needs the vehicle (plate number).');
        }

        if (!$entity->get('orderDateTime')) {
            throw new BadRequest('reserve_missing_time: a reservation needs the arrival time (orderDateTime).');
        }
    }
}
