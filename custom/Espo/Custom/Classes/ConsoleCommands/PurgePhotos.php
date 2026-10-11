<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\Kiosk\PhotoRetention;

/**
 * Delete order photos whose retention date (`OrderPhoto.retainUntil`) has passed — the row
 * and the image file. The same routine runs daily as the scheduled job PurgeExpiredPhotos.
 *
 *     bin/command purge-photos            # delete
 *     bin/command purge-photos --dry-run  # only count
 */
class PurgePhotos implements Command
{
    public function __construct(private PhotoRetention $retention)
    {}

    public function run(Params $params, IO $io): void
    {
        $dry = $params->hasFlag('dryRun');
        $r = $this->retention->purge($dry);

        $io->writeLine($dry
            ? "{$r['expired']} photo(s) are past their retention date (dry run, nothing deleted)."
            : "Deleted {$r['removed']} expired photo(s).");
    }
}
