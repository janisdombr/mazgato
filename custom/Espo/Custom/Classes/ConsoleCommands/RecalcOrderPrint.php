<?php

namespace Espo\Custom\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\Tools\OrderPrice\Recalculator;
use Espo\ORM\EntityManager;

/**
 * One-off, idempotent: re-derive the printable columns of every existing order
 * (paymentMethodText in the default language, linesHtml, totalsHtml, VAT split) through the
 * same Recalculator the hooks use. Fixes orders printed before the D30 language fix.
 * Re-running it changes nothing further.
 *
 *     bin/command recalc-order-print
 */
class RecalcOrderPrint implements Command
{
    public function __construct(
        private EntityManager $entityManager,
        private Recalculator $recalculator
    ) {}

    public function run(Params $params, IO $io): void
    {
        $orders = $this->entityManager->getRDBRepository('WashOrder')
            ->select(['id'])
            ->where(['deleted' => false])
            ->find();

        $n = 0;

        foreach ($orders as $order) {
            $this->recalculator->recalculate($order->getId());
            $n++;
        }

        $io->writeLine("Recalculated printable fields of $n orders.");
    }
}
