<?php

namespace Espo\Custom\Tools\OrderPrice;

use Espo\Core\Utils\Language;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * ONE truth about the order's money: the OrderAddon lines.
 *
 * Whenever a line changes — from the Quick Intake form, the order's lines panel, an
 * add-on ticked on the order, or the API — this class re-derives everything money-shaped
 * from the lines and writes it onto the order in a single direct UPDATE (no entity save,
 * so no hook re-entry):
 *
 *   finalPrice          = sum of line prices (only while the order HAS lines);
 *   amountNet/vatAmount = the 21% VAT split of that total;
 *   linesHtml           = the printable <table> of lines (see SyncOrderAddons for why a
 *                         complete table, never bare rows or {{#each}});
 *   totalsHtml          = the printable totals block — with the PVN breakdown when the
 *                         order's `pvnEnabled` is ticked, a single "Kopā" row otherwise;
 *   paymentMethodText   = the payment method in the DEFAULT (config) language — printed
 *                         documents are always Latvian, whatever the operator's UI is.
 *
 * An order with no lines (e.g. a bare reservation) keeps whatever finalPrice it was given.
 */
class Recalculator
{
    private const VAT_RATE = 0.21;

    public function __construct(
        private EntityManager $entityManager,
        // The DEFAULT (config) language — lv_LV — not the language of whoever is clicking.
        // Binding is by PARAMETER NAME: a param called $language gets the *current user's*
        // language (Binding.php 'language'), $defaultLanguage gets the config one.
        private Language $defaultLanguage
    ) {}

    public function recalculate(string $orderId): void
    {
        $em = $this->entityManager;

        $order = $em->getEntityById('WashOrder', $orderId);

        if (!$order) {
            return;
        }

        $lines = $em->getRDBRepository('OrderAddon')
            ->where(['washOrderId' => $orderId, 'deleted' => false])
            ->order('sortOrder')
            ->find();

        $rows = '';
        $total = 0.0;
        $hasLines = false;

        foreach ($lines as $line) {
            $hasLines = true;
            $total += (float) $line->get('price');

            $name = htmlspecialchars((string) $line->get('name'), ENT_QUOTES, 'UTF-8');
            $price = number_format((float) $line->get('price'), 2, '.', '');

            $rows .= '<tr><td style="padding:2px 0">' . $name . '</td>' .
                '<td style="padding:2px 0; text-align:right; width:110px">' . $price . ' EUR</td></tr>';
        }

        $total = round($total, 2);

        // A COMPLETE table, not bare <tr> rows: the placeholder in the template must be a
        // block-level element. A `{{{linesHtml}}}` sitting inside <table> is bare text in
        // an invalid position, and the template editor hoists it out on the next save —
        // the rows then land outside any table and print as one run-on line.
        $rows = $rows === ''
            ? ''
            : '<table style="width:100%; font-size:13px; border-collapse:collapse">' .
                '<tbody>' . $rows . '</tbody></table>';

        // The order's price IS the sum of its lines. Only a line-less order (a bare
        // reservation) keeps a hand-entered finalPrice.
        $gross = $hasLines ? $total : (float) $order->get('finalPrice');

        $net = round($gross / (1 + self::VAT_RATE), 2);
        $vat = round($gross - $net, 2);

        $method = $order->get('paymentMethod');

        $methodText = $method
            ? $this->defaultLanguage->translateOption($method, 'paymentMethod', 'WashOrder')
            : '';

        $set = [
            'linesHtml' => $rows,
            'totalsHtml' => $this->buildTotalsHtml($gross, $net, $vat, (bool) $order->get('pvnEnabled')),
            'paymentMethodText' => is_string($methodText) ? $methodText : (string) $method,
            'amountNet' => $net,
            'vatAmount' => $vat,
        ];

        if ($hasLines) {
            $set['finalPrice'] = $total;
        }

        $update = $em->getQueryBuilder()
            ->update()
            ->in('WashOrder')
            ->set($set)
            ->where(Cond::equal(Cond::column('id'), $orderId))
            ->build();

        $em->getQueryExecutor()->execute($update);
    }

    /**
     * The printable totals block, always in Latvian. With PVN enabled it is the full
     * breakdown (net / PVN 21% / total incl. PVN); without it, a single total row —
     * an invoice for a non-PVN sale must not mention VAT at all.
     */
    private function buildTotalsHtml(float $gross, float $net, float $vat, bool $pvnEnabled): string
    {
        $fmt = fn (float $v): string => number_format($v, 2, '.', '');

        $right = 'style="text-align:right; padding:2px 0"';
        $rightW = 'style="text-align:right; padding:2px 0; width:130px"';

        if (!$pvnEnabled) {
            return '<table style="width:100%; font-size:14px; border-collapse:collapse"><tbody>' .
                '<tr><td ' . $right . '><b>Kopā apmaksai:</b></td>' .
                '<td ' . $rightW . '><b>' . $fmt($gross) . ' EUR</b></td></tr>' .
                '</tbody></table>';
        }

        return '<table style="width:100%; font-size:13px; border-collapse:collapse"><tbody>' .
            '<tr><td ' . $right . '>Summa bez PVN:</td>' .
            '<td ' . $rightW . '>' . $fmt($net) . ' EUR</td></tr>' .
            '<tr><td ' . $right . '>Tajā skaitā PVN 21%:</td>' .
            '<td ' . $rightW . '>' . $fmt($vat) . ' EUR</td></tr>' .
            '<tr><td ' . $right . '><b>Kopā apmaksai (ar PVN):</b></td>' .
            '<td ' . $rightW . '><b>' . $fmt($gross) . ' EUR</b></td></tr>' .
            '</tbody></table>';
    }
}
