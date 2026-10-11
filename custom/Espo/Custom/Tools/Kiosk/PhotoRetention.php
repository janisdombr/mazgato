<?php

namespace Espo\Custom\Tools\Kiosk;

use Espo\ORM\EntityManager;

/**
 * Photo retention: an OrderPhoto is deleted (record AND file — the Attachment goes with it)
 * once its `retainUntil` day is past. `retainUntil` is stamped on save from the
 * `photoRetentionDays` setting (`Hooks/OrderPhoto/SetRetention`), so shortening the
 * setting later affects new photos only; an operator can still edit a single photo's date.
 * Run by `bin/command purge-photos` and by the daily scheduled job.
 */
class PhotoRetention
{
    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * @return array{expired: int, removed: int}
     */
    public function purge(bool $dryRun = false): array
    {
        $photos = $this->entityManager->getRDBRepository('OrderPhoto')
            ->where([
                'deleted' => false,
                'retainUntil!=' => null,
                'retainUntil<' => gmdate('Y-m-d'),
            ])
            ->find();

        $expired = 0;
        $removed = 0;

        foreach ($photos as $photo) {
            $expired++;

            if ($dryRun) {
                continue;
            }

            $this->entityManager->removeEntity($photo);
            $removed++;
        }

        return ['expired' => $expired, 'removed' => $removed];
    }
}
