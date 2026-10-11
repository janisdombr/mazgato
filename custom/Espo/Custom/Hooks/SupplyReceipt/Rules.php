<?php

namespace Espo\Custom\Hooks\SupplyReceipt;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;

/**
 * A receipt is a Draft until Tools/Inventory posts it (which raises the stock exactly once). Through the entity API a
 * receipt can be created as a Draft and edited while Draft; it can never be set to Posted by hand, and a Posted one is
 * locked (no edit, no delete) - the stock and the journal already moved.
 */
class Rules
{
    public static $order = 1;

    public function __construct(private Config $config)
    {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $server = !empty($options['allowLedgerWrite']);

        if ($entity->isNew()) {
            if (!$server) {
                $entity->set('status', 'Draft');
            }

            if (!$entity->get('receiptDate')) {
                $tz = new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC');
                $entity->set('receiptDate', (new \DateTime('now', $tz))->format('Y-m-d'));
            }

            if (!$entity->get('name')) {
                $entity->set('name', $entity->get('invoiceNumber') ?: 'RC-' . date('ymd') . '-' . bin2hex(random_bytes(2)));
            }

            return;
        }

        if ($server) {
            return;
        }

        if ($entity->getFetched('status') === 'Posted') {
            throw new Forbidden('receipt_posted_locked');
        }

        if ($entity->get('status') === 'Posted') {
            throw new Forbidden('receipt_post_via_action');
        }
    }

    public function beforeRemove(Entity $entity, array $options = []): void
    {
        if (empty($options['allowLedgerWrite']) && $entity->get('status') === 'Posted') {
            throw new Forbidden('receipt_posted_locked');
        }
    }
}
