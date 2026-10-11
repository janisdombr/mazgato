<?php

namespace Espo\Custom\Hooks\Camera;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Crypt;
use Espo\ORM\Entity;

/**
 * A camera record never holds a login in the open.
 *
 *   code / streamName   plain identifiers only (no scheme, no `user:pass@host`): the stream is
 *                       published by the reverse proxy as a NAME, the credentials stay at the wash.
 *   secretInput         WRITE-ONLY: whatever is typed here is encrypted into `secretCipher`
 *                       (AES-256, instance `cryptKey`) and the field itself is never stored or
 *                       returned; `secretSet` only says that one exists. `secretClear` removes it.
 *                       `secretCipher` is `forbidden` in entityAcl, so the REST API never shows it;
 *                       only the owning edge node receives it (through its own token).
 */
class ProtectSecret
{
    public static $order = 5;

    public function __construct(private Crypt $crypt)
    {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options = []): void
    {
        $code = strtolower(trim((string) $entity->get('code')));

        if ($entity->isAttributeChanged('code') || $entity->isNew()) {
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,38}$/', $code)) {
                throw new BadRequest("Camera code: 2-39 characters, a-z 0-9 _ - (it names the camera for the edge node).");
            }

            $entity->set('code', $code);
        }

        $stream = trim((string) $entity->get('streamName'));

        if ($stream !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,59}$/', $stream)) {
            throw new BadRequest('Stream name: only a name (letters, digits, _ . -). No address, no login.');
        }

        $entity->set('streamName', $stream !== '' ? $stream : null);

        if ($entity->get('secretClear')) {
            $entity->set(['secretCipher' => null, 'secretSet' => false]);
        }

        $input = $entity->get('secretInput');

        if (is_string($input) && $input !== '') {
            $entity->set(['secretCipher' => $this->crypt->encrypt($input), 'secretSet' => true]);
        }

        // never keep the plain value on the entity or in what the save answers
        $entity->clear('secretInput');
        $entity->clear('secretClear');
    }
}
