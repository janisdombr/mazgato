<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Import\CustomerImporter;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Entities\User;
use stdClass;

/**
 * ImportData/action/*   (administrator only; a tablet's key opens nothing here)
 *
 *  customersHeader { content, delimiter?, encoding? }          -> header, first rows, suggested mapping, targets
 *  customers       { content, map, dryRun = true, delimiter?, encoding? } -> the report (Tools/Import/CustomerImporter)
 *
 * The browser sends the CSV text; nothing is stored on disk. Same engine as `bin/command import-customers`.
 */
class ImportData
{
    private const MAX_BYTES = 6_000_000;

    public function __construct(private User $user, private CustomerImporter $importer, DeviceGuard $deviceGuard)
    {
        $deviceGuard->denyDevice($user);

        if (!$user->isAdmin()) {
            throw new Forbidden();
        }
    }

    /** @return array{header: list<string>, rows: list<list<string>>} */
    private function table(stdClass $d): array
    {
        $content = (string) ($d->content ?? '');

        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            throw new BadRequest($content === '' ? 'empty_file' : 'too_large');
        }

        $text = CustomerImporter::toUtf8($content, !empty($d->encoding) ? (string) $d->encoding : null);

        return CustomerImporter::parse($text, !empty($d->delimiter) ? (string) $d->delimiter : null);
    }

    public function postActionCustomersHeader(Request $request): stdClass
    {
        $t = $this->table($request->getParsedBody());

        return (object) [
            'header' => $t['header'], 'rows' => array_slice($t['rows'], 0, 5), 'total' => count($t['rows']),
            'suggest' => (object) CustomerImporter::suggestMap($t['header']), 'targets' => CustomerImporter::TARGETS,
        ];
    }

    public function postActionCustomers(Request $request): stdClass
    {
        $d = $request->getParsedBody();
        $map = [];

        foreach ((array) ($d->map ?? []) as $k => $v) {
            $map[(string) $k] = (string) $v;
        }

        $report = $this->importer->run($this->table($d), $map, !isset($d->dryRun) || $d->dryRun !== false);

        return (object) $report;
    }
}
