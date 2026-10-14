<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\QuickStart\Checker;
use Espo\Custom\Tools\QuickStart\Editor;
use Espo\Custom\Tools\QuickStart\Starter;
use Espo\Entities\User;
use stdClass;

/**
 * QuickStart/action/*   (administrator only; a tablet's key opens nothing here) - the "Quick start" wizard, docs/QUICKSTART.md
 *
 *  state                              -> the whole catalogue for the one-table editor
 *  save { roles, employees, works, services }  -> validated and written in ONE transaction, answers the new state
 *  check                              -> completeness issues (Tools/QuickStart/Checker) + counters
 *  loadExample { lang?, namePrefix? } -> the starter set (Tools/QuickStart/Starter): idempotent, never edits what exists
 */
class QuickStart
{
    public function __construct(
        private User $user,
        private Editor $editor,
        private Checker $checker,
        private Starter $starter,
        DeviceGuard $deviceGuard
    ) {
        $deviceGuard->denyDevice($user);

        if (!$user->isAdmin()) {
            throw new Forbidden();
        }
    }

    public function postActionState(Request $request): stdClass
    {
        return (object) $this->editor->state();
    }

    public function postActionSave(Request $request): stdClass
    {
        return (object) $this->editor->save($request->getParsedBody());
    }

    public function postActionCheck(Request $request): stdClass
    {
        return (object) ($this->checker->run() + ['loadedAt' => $this->starter->loadedAt()]);
    }

    public function postActionLoadExample(Request $request): stdClass
    {
        $d = $request->getParsedBody();
        $prefix = (string) ($d->namePrefix ?? '');

        if (mb_strlen($prefix) > 16) {
            throw new BadRequest('invalid_value: namePrefix is at most 16 characters.');
        }

        $lang = isset($d->lang) ? (string) $d->lang : null;

        if ($lang !== null && !in_array($lang, Starter::LANGS, true)) {
            throw new BadRequest('invalid_value: lang must be lv_LV, ru_RU, en_US or lt_LT.');
        }

        $r = $this->starter->load($lang, $prefix);
        $r['created'] = (object) $r['created'];
        $r['skipped'] = (object) $r['skipped'];
        $r['ids'] = (object) $r['ids'];

        return (object) ($r + ['state' => $this->editor->state(), 'loadedAt' => $this->starter->loadedAt()]);
    }
}
