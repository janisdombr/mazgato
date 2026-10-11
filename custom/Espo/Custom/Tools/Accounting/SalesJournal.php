<?php

namespace Espo\Custom\Tools\Accounting;

use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;

/**
 * The sales journal for the accountant: every COMPLETED order of a period, once, from the single ledger
 * (the same rows the Statistics dashlet sums as revenue - cancelled, reserved and in-progress orders are
 * not sales and never appear). One row per order (`lines = false`) or one row per priced line
 * (`lines = true`, the OrderAddon rows: base wash + add-ons), in a neutral CSV or XML.
 *
 * Not an import file of any particular accounting program: the Jumis / Horizon import specifications could
 * not be verified (docs/FISCAL.md section 5), so the columns are documented and stable, and the accountant
 * maps them in the program's import. Period = the visit date (`orderDateTime`, local calendar days), the
 * same rule as Statistics, so the totals reconcile.
 */
class SalesJournal
{
    public const COLUMNS = [
        'doc_no', 'date', 'completed_at', 'customer', 'customer_reg_no', 'customer_pvn_no', 'plate', 'item',
        'quantity', 'net', 'vat_rate', 'vat', 'gross', 'currency', 'pvn_shown', 'payment_method', 'payment_status',
        'fiscal_status', 'fiscal_series', 'fiscal_no', 'fiscal_device', 'fiscal_time',
    ];

    /** Columns that are numbers formatted here - never prefixed against formula injection. */
    private const NUMERIC = ['quantity', 'net', 'vat_rate', 'vat', 'gross'];

    public function __construct(private EntityManager $em, private Config $config)
    {}

    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC');
    }

    private function local(?string $utc, string $fmt): string
    {
        if (!$utc) {
            return '';
        }

        try {
            return (new \DateTime($utc, new \DateTimeZone('UTC')))->setTimezone($this->tz())->format($fmt);
        } catch (\Exception $e) {
            return '';
        }
    }

    /** @return array{0: string, 1: string} UTC bounds of the local days dateFrom..dateTo (inclusive) */
    public function bounds(string $from, string $to): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            (new \DateTime($from . ' 00:00:00', $this->tz()))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new \DateTime($to . ' 23:59:59', $this->tz()))->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{rows: list<array<string, string>>, orders: int, totals: array<string, float>, byMethod: array<string, float>, fiscalPending: int}
     */
    public function build(string $from, string $to, bool $lines): array
    {
        [$fromU, $toU] = $this->bounds($from, $to);

        $orders = $this->em->getRDBRepository('WashOrder')
            ->where(['status' => 'Completed', 'orderDateTime>=' => $fromU, 'orderDateTime<=' => $toU, 'deleted' => false])
            ->order('orderDateTime', 'ASC')->order('name', 'ASC')
            ->find();

        $rows = [];
        $n = 0;
        $totals = ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
        $byMethod = [];
        $pending = 0;

        foreach ($orders as $o) {
            $n++;
            $gross = round((float) $o->get('finalPrice'), 2);
            $vat = round((float) $o->get('vatAmount'), 2);
            $net = round((float) $o->get('amountNet'), 2);

            if ($net == 0.0 && $gross != 0.0 && $vat == 0.0) {
                $net = $gross;
            }

            $totals['net'] += $net;
            $totals['vat'] += $vat;
            $totals['gross'] += $gross;
            $m = (string) ($o->get('paymentMethod') ?: 'Unknown');
            $byMethod[$m] = ($byMethod[$m] ?? 0.0) + $gross;

            if ($o->get('fiscalStatus') === 'Pending') {
                $pending++;
            }

            $rate = $net > 0 ? round($vat / $net * 100, 2) : 0.0;

            // 3.12 / 14.88 = 20.97 % is cent rounding of a 21 % rate: report the nominal whole rate when it is within rounding.
            if ($rate > 0 && abs($rate - round($rate)) <= 0.25) {
                $rate = (float) round($rate);
            }
            $head = [
                'doc_no' => (string) $o->get('name'),
                'date' => $this->local($o->get('orderDateTime'), 'Y-m-d'),
                'completed_at' => $this->local($o->get('finishedAt'), 'Y-m-d H:i:s'),
                'customer' => (string) ($o->get('customerName') ?? ''),
                'customer_reg_no' => (string) ($o->get('customerRegNumber') ?? ''),
                'customer_pvn_no' => (string) ($o->get('customerPvnNumber') ?? ''),
                'plate' => (string) ($o->get('vehiclePlate') ?? ''),
                'currency' => 'EUR',
                'pvn_shown' => $o->get('pvnEnabled') ? '1' : '0',
                'payment_method' => $m,
                'payment_status' => (string) $o->get('paymentStatus'),
                'fiscal_status' => (string) ($o->get('fiscalStatus') ?? ''),
                'fiscal_series' => (string) ($o->get('fiscalReceiptSeries') ?? ''),
                'fiscal_no' => (string) ($o->get('fiscalReceiptNo') ?? ''),
                'fiscal_device' => (string) ($o->get('fiscalDeviceId') ?? ''),
                'fiscal_time' => $this->local($o->get('fiscalPrintedAt'), 'Y-m-d H:i:s'),
            ];

            if (!$lines) {
                $svc = array_filter([(string) ($o->get('serviceName') ?? '')]);
                $rows[] = $head + [
                    'item' => implode(' + ', $svc), 'quantity' => '1', 'net' => $this->num($net), 'vat_rate' => $this->num($rate),
                    'vat' => $this->num($vat), 'gross' => $this->num($gross),
                ];

                continue;
            }

            // One row per priced line. The order's VAT is split in proportion to the line's share of the
            // gross total; the LAST line takes the rounding remainder so the lines always add up to the order.
            $items = $this->em->getRDBRepository('OrderAddon')->where(['washOrderId' => $o->getId(), 'deleted' => false])
                ->order('sortOrder', 'ASC')->find();
            $list = [];

            foreach ($items as $it) {
                $list[] = [(string) $it->get('name'), round((float) $it->get('price'), 2)];
            }

            if (!$list) {
                $list[] = [(string) ($o->get('serviceName') ?? ''), $gross];
            }

            $sum = array_sum(array_column($list, 1));
            $restNet = $net;
            $restVat = $vat;
            $restGross = $gross;
            $last = count($list) - 1;

            foreach ($list as $i => [$name, $price]) {
                if ($i === $last) {
                    $lg = round($restGross, 2);
                    $ln = round($restNet, 2);
                    $lv = round($restVat, 2);
                } else {
                    $share = $sum > 0 ? $price / $sum : 0;
                    $lg = round($gross * $share, 2);
                    $ln = round($net * $share, 2);
                    $lv = round($vat * $share, 2);
                    $restGross -= $lg;
                    $restNet -= $ln;
                    $restVat -= $lv;
                }

                $rows[] = $head + ['item' => $name, 'quantity' => '1', 'net' => $this->num($ln), 'vat_rate' => $this->num($rate),
                    'vat' => $this->num($lv), 'gross' => $this->num($lg)];
            }
        }

        return [
            'rows' => $rows, 'orders' => $n,
            'totals' => array_map(fn($v) => round($v, 2), $totals),
            'byMethod' => array_map(fn($v) => round($v, 2), $byMethod),
            'fiscalPending' => $pending,
        ];
    }

    private function num(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /** A text cell that Excel/LibreOffice would run as a formula gets a leading apostrophe. */
    public static function safe(string $col, string $v): string
    {
        if ($v === '' || in_array($col, self::NUMERIC, true)) {
            return $v;
        }

        return in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }

    /** CSV with UTF-8 BOM (Excel), header row, `;` or `,` separated, decimal point or comma. */
    public static function csv(array $rows, string $delimiter = ';', string $decimal = '.'): string
    {
        $delimiter = $delimiter === ',' ? ',' : ';';
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::COLUMNS, $delimiter, '"', '\\');

        foreach ($rows as $r) {
            $line = [];

            foreach (self::COLUMNS as $c) {
                $v = self::safe($c, (string) ($r[$c] ?? ''));
                $line[] = ($decimal === ',' && in_array($c, self::NUMERIC, true)) ? str_replace('.', ',', $v) : $v;
            }

            fputcsv($fh, $line, $delimiter, '"', '\\');
        }

        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return "\xEF\xBB\xBF" . $out;
    }

    /** Neutral XML: <salesJournal><row>..</row></salesJournal>, one element per column. */
    public static function xml(array $rows, string $from, string $to): string
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('salesJournal');
        $w->writeAttribute('from', $from);
        $w->writeAttribute('to', $to);
        $w->writeAttribute('currency', 'EUR');
        $w->writeAttribute('rows', (string) count($rows));

        foreach ($rows as $r) {
            $w->startElement('row');

            foreach (self::COLUMNS as $c) {
                $w->writeElement($c, self::stripControl((string) ($r[$c] ?? '')));
            }

            $w->endElement();
        }

        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    private static function stripControl(string $v): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    }
}
