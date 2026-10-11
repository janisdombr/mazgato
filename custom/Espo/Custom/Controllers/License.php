<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Licensing\License as Lic;
use Espo\Custom\Tools\Licensing\Modules;
use Espo\Entities\User;
use stdClass;

/**
 * License/action/*   (administrator only, never reachable with a tablet key)
 *
 *  status   installationId, enforced?, verdict (status, customer, modules, expires, grace), per-module state
 *  upload   { content }  verify and install a licence file; refused when the signature does not verify
 *           or it belongs to another installation (an EXPIRED one is accepted and shown as expired)
 *  remove   delete the installed licence file
 *
 * Offline only: nothing here contacts the vendor.
 */
class License
{
    public function __construct(private User $user, private Lic $license, private Modules $modules, DeviceGuard $deviceGuard)
    {
        $deviceGuard->denyDevice($user);

        if (!$user->isAdmin()) {
            throw new Forbidden();
        }
    }

    public function postActionStatus(Request $request): stdClass
    {
        return $this->view();
    }

    public function postActionUpload(Request $request): stdClass
    {
        $content = (string) ($request->getParsedBody()->content ?? '');

        if (strlen($content) > 20000) {
            throw new BadRequest('too_large');
        }

        $v = $this->license->checkContent($content);

        if (in_array($v['status'], ['malformed', 'no_key', 'bad_signature', 'wrong_installation'], true)) {
            throw new BadRequest($v['status']);
        }

        if (!$this->license->install($content)) {
            throw new BadRequest('not_writable');
        }

        return $this->view();
    }

    public function postActionRemove(Request $request): stdClass
    {
        $this->license->remove();

        return $this->view();
    }

    private function view(): stdClass
    {
        $v = $this->license->verdict();
        $p = $v['payload'] ?? [];
        $mods = [];

        foreach (Modules::ALL as $m) {
            $mods[$m] = ['licensed' => $this->license->covers($m), 'enabled' => $this->modules->moduleEnabled($m)];
        }

        return (object) [
            'installationId' => $this->license->installationId(),
            'enforced' => $this->license->enforced(),
            'hasKey' => $this->license->publicKey() !== '',
            'status' => $v['status'],
            'customer' => $p['customer'] ?? null,
            'modules' => $p['modules'] ?? [],
            'issued' => $p['issued'] ?? null,
            'expires' => $p['expires'] ?? null,
            'graceUntil' => $v['graceUntil'] ? gmdate('Y-m-d', $v['graceUntil']) : null,
            'moduleState' => (object) array_map(fn($x) => (object) $x, $mods),
        ];
    }
}
