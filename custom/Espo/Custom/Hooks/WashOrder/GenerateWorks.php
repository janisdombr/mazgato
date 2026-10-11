<?php

namespace Espo\Custom\Hooks\WashOrder;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Expand the order's services into a list of works.
 *
 * A service (base wash or add-on) is a *preset*: its ServiceWork lines say which works
 * it brings and, optionally, which role does them (otherwise the work's default role).
 * Each generated OrderWork gets the employee assigned to that role for this order.
 *
 * Runs per service and skips any service that already has lines, so it is safe on every
 * save: ticking an extra add-on later only adds that add-on's works, and manual edits or
 * deletions of existing lines are never overwritten.
 *
 * The role → employee map comes from `$options['roleMap']` (the intake endpoint) or,
 * failing that, from the order's three legacy worker fields mapped onto the first three
 * roles by sort order.
 */
class GenerateWorks
{
    public static $order = 20;

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipGenerateWorks'])) {
            return;
        }

        $serviceIds = [];

        if ($entity->get('serviceId')) {
            $serviceIds[] = $entity->get('serviceId');
        }

        foreach ($entity->getLinkMultipleIdList('addons') as $addonId) {
            $serviceIds[] = $addonId;
        }

        if (!$serviceIds) {
            return;
        }

        $roleMap = $this->buildRoleMap($entity, $options);

        // Per-work overrides ("serviceId:workId" => employeeId) beat the role default,
        // so a single add-on can be handed to somebody other than its role's performer.
        $workMap = (!empty($options['workMap']) && is_array($options['workMap'])) ? $options['workMap'] : [];

        // A work is added to the order at most once. The base wash and an add-on can list
        // the same work; without this the order would carry it twice. The per-service guard
        // below only stops a whole service from re-expanding — it cannot see across services.
        // Seed the set with works already on the order so a later add-on never re-adds one
        // the base already brought; the first service to bring a work wins.
        $seenWorkIds = [];

        $existingWorks = $this->entityManager
            ->getRDBRepository('OrderWork')
            ->select(['workId'])
            ->where(['washOrderId' => $entity->getId()])
            ->find();

        foreach ($existingWorks as $existingWork) {
            if ($existingWork->get('workId')) {
                $seenWorkIds[$existingWork->get('workId')] = true;
            }
        }

        foreach ($serviceIds as $serviceId) {
            $already = $this->entityManager
                ->getRDBRepository('OrderWork')
                ->where([
                    'washOrderId' => $entity->getId(),
                    'serviceId' => $serviceId,
                ])
                ->findOne();

            if ($already) {
                continue;
            }

            $presets = $this->entityManager
                ->getRDBRepository('ServiceWork')
                ->where(['serviceId' => $serviceId])
                ->order('sortOrder')
                ->find();

            foreach ($presets as $preset) {
                $workId = $preset->get('workId');

                if (!$workId) {
                    continue;
                }

                // Already on the order (from an earlier service or a previous save) — skip.
                if (isset($seenWorkIds[$workId])) {
                    continue;
                }

                $work = $this->entityManager->getEntityById('Work', $workId);
                $roleId = $preset->get('roleId') ?: ($work ? $work->get('defaultRoleId') : null);

                $employeeId = $workMap[$serviceId . ':' . $workId]
                    ?? (($roleId && isset($roleMap[$roleId])) ? $roleMap[$roleId] : null);

                $this->entityManager->createEntity('OrderWork', [
                    'washOrderId' => $entity->getId(),
                    'workId' => $workId,
                    'serviceId' => $serviceId,
                    'roleId' => $roleId,
                    'employeeId' => $employeeId,
                ]);

                $seenWorkIds[$workId] = true;
            }
        }
    }

    /** @return array<string, string> roleId => employeeId */
    private function buildRoleMap(Entity $entity, array $options): array
    {
        if (!empty($options['roleMap']) && is_array($options['roleMap'])) {
            return $options['roleMap'];
        }

        $roles = $this->entityManager
            ->getRDBRepository('WorkRole')
            ->where(['isActive' => true])
            ->order('sortOrder')
            ->limit(0, 3)
            ->find();

        $legacyFields = ['washerId', 'dryerId', 'salonCleanerId'];
        $map = [];
        $i = 0;

        foreach ($roles as $role) {
            $field = $legacyFields[$i] ?? null;
            $employeeId = $field ? $entity->get($field) : null;

            if ($employeeId) {
                $map[$role->getId()] = $employeeId;
            }

            $i++;
        }

        return $map;
    }
}
