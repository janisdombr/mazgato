<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Import\CustomerImporter;

/**
 * bin/command import-customers --file=PATH --map='name:Name,phone:Phone,plate:Plate' | --auto [--commit] [--delimiter=;] [--encoding=Windows-1257] [--json]
 *
 * Imports customers and their cars from another system's CSV (docs/IMPORT.md). SAFE BY DEFAULT: without --commit (or
 * with the explicit --dry-run) nothing is written and the report says what WOULD happen. --template prints a ready CSV header with an example row.
 * The file must be readable INSIDE the container (docker compose cp file.csv espocrm-app:/tmp/c.csv).
 */
class ImportCustomers implements Command
{
    public function __construct(private CustomerImporter $importer)
    {}

    public function run(Params $params, IO $io): void
    {
        if ($params->hasFlag('template')) {
            $io->write(self::template());

            return;
        }

        $file = (string) $params->getOption('file');
        $mapArg = (string) $params->getOption('map');

        if ($file === '' || ($mapArg === '' && !$params->hasFlag('auto'))) {
            $io->writeLine("usage: import-customers --file=PATH --map='name:Name,phone:Phone,email:E-mail,plate:Plate' | --auto [--commit] [--dry-run] [--delimiter=;] [--encoding=Windows-1257] [--json]");
            $io->writeLine("       import-customers --template");
            $io->setExitStatus(2);

            return;
        }

        if (!is_file($file) || !is_readable($file)) {
            $io->writeLine('error: cannot read ' . $file);
            $io->setExitStatus(2);

            return;
        }

        try {
            $text = CustomerImporter::toUtf8((string) file_get_contents($file), $params->getOption('encoding') ?: null);
            $table = CustomerImporter::parse($text, $params->getOption('delimiter') ?: null);
            $map = $mapArg !== '' ? CustomerImporter::parseMapString($mapArg) : CustomerImporter::suggestMap($table['header']);

            if ($mapArg === '') {
                $io->writeLine('auto map: ' . json_encode($map, JSON_UNESCAPED_UNICODE));
            }

            $report = $this->importer->run($table, $map, !$params->hasFlag('commit'));
        } catch (\Espo\Core\Exceptions\BadRequest $e) {
            $io->writeLine('error: ' . $e->getMessage());
            $io->setExitStatus(2);

            return;
        }

        if ($params->hasFlag('json')) {
            $io->writeLine((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }

        $io->writeLine(($report['dryRun'] ? 'DRY RUN - nothing was written (add --commit to import)' : 'IMPORTED') . ' - ' . $report['rows'] . ' rows');
        $io->writeLine(sprintf('customers: %d new, %d already there; cars: %d new, %d already there; rows skipped: %d',
            $report['customersCreated'], $report['customersMatched'], $report['vehiclesCreated'], $report['vehiclesExisting'], $report['skipped']));

        foreach ($report['reasons'] as $reason => $n) {
            $io->writeLine('  ' . $reason . ': ' . $n);
        }

        foreach (array_slice($report['problems'], 0, 20) as $p) {
            $io->writeLine('  line ' . $p['line'] . ': ' . $p['reason'] . ($p['detail'] !== '' ? ' (' . $p['detail'] . ')' : ''));
        }
    }

    public static function template(): string
    {
        return "name;phone;email;plate;brand;model;reg_number;pvn_number;legal_address;birthday;notes;marketing_email\n"
            . "Jānis Bērziņš;+371 20000000;janis@example.com;AB-1234;Volkswagen;Golf;;;;1985-03-21;prefers morning;no\n"
            . "SIA Example Wash Client;+371 26000000;office@example.lv;CD5678, EF9012;;;40003000000;LV40003000000;Brīvības iela 1, Rīga;;Fleet account;no\n";
    }
}
