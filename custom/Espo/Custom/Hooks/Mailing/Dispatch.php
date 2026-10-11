<?php

namespace Espo\Custom\Hooks\Mailing;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When a mailing is marked "Sent" by hand (without the Send action), stamp the time and
 * count the opted-in, reachable audience. Actual delivery + per-recipient results are
 * done by CarWash/action/sendMailing (the "Send" button) — this hook only records that
 * the mailing was marked sent, so a manual status change is never silent.
 */
class Dispatch
{
    public static $order = 10;

    /** channel -> [consent flag on Customer, required contact field] */
    private const CHANNEL_MAP = [
        'WhatsApp' => ['allowWhatsapp', 'phone'],
        'Phone' => ['allowPhone', 'phone'],
        'Email' => ['allowEmail', 'email'],
    ];

    public function __construct(private EntityManager $entityManager)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipMailingDispatch'])) {
            return;
        }

        if ($entity->get('status') !== 'Sent') {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('status')) {
            return;
        }

        if (!$entity->get('sentAt')) {
            $entity->set('sentAt', date('Y-m-d H:i:s'));
        }

        $map = self::CHANNEL_MAP[(string) $entity->get('channel')] ?? null;

        if (!$map) {
            return;
        }

        [$consentField, $contactField] = $map;

        $count = $this->entityManager
            ->getRDBRepository('Customer')
            ->where([$consentField => true, 'deleted' => false])
            ->where([$contactField . '!=' => null])
            ->where([$contactField . '!=' => ''])
            ->count();

        $entity->set('recipientCount', $count);
    }
}
