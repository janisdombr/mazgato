<?php

namespace Espo\Custom\Tools\Accounting\Connectors;

use Espo\Custom\Tools\Accounting\ConnectorException;
use Espo\Custom\Tools\Accounting\Http;

/**
 * Merit Aktiva (Merit Software, Estonia). Source: https://api.merit.ee/connecting-robots/reference-manual/ (read 2026-10-12).
 *
 * Documented: JSON over HTTPS; every call carries ApiId, timestamp (UTC yyyyMMddHHmmss) and signature = base64(HMAC-SHA256(
 * apiId + timestamp + body, apiKey)) in the query string; 100 requests per minute (429 + Retry-After); hosts aktiva.merit.ee
 * (Estonian) and program.360ksiegowosc.pl (Polish) only - no Latvian host is documented; sendinvoice has no duplicate
 * protection of its own (the page says to check before retrying) - hence findSale() via getinvoices2 before every create.
 *
 * NOT verified against a live account (see docs/ACCOUNTING_INTEGRATIONS.md): the exact tax-list path (the manual shows both
 * "gettaxes" and "taxes"), whether NotTDCustomer=true means "private person", cent differences between our row VAT and
 * Merit's own recalculation, the sendpayment answer, whether a Latvian company can be registered with the Estonian host at all.
 */
class MeritConnector extends BaseConnector
{
    public function code(): string
    {
        return 'merit';
    }

    public function label(): string
    {
        return 'Merit Aktiva';
    }

    public function isConfigured(): bool
    {
        return $this->cfg->str('accountingMeritId') !== '' && $this->cfg->secret('accountingMeritKey')->isSet();
    }

    private function base(): string
    {
        $t = $this->cfg->testBase();

        if ($t !== null) {
            return $t . '/api/';
        }

        return $this->cfg->str('accountingMeritHost', 'ee') === 'pl' ? 'https://program.360ksiegowosc.pl/api/' : 'https://aktiva.merit.ee/api/';
    }

    /** @return mixed decoded answer */
    private function call(string $method, string $path, array $payload): mixed
    {
        $id = $this->cfg->str('accountingMeritId');
        $key = $this->cfg->secret('accountingMeritKey');
        $body = json_encode((object) $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $ts = gmdate('YmdHis');
        $sig = base64_encode(hash_hmac('sha256', $id . $ts . $body, $key->reveal(), true));
        $url = $this->base() . $path . '?ApiId=' . rawurlencode($id) . '&timestamp=' . $ts . '&signature=' . rawurlencode($sig);
        $r = Http::send($method, $url, ['Content-Type: application/json', 'Accept: application/json'], $body);
        $this->check($r);

        return $this->json($r);
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        try {
            $taxes = $this->taxes();

            return ['ok' => true, 'message' => 'ok', 'details' => ['taxes' => count($taxes)]];
        } catch (ConnectorException $e) {
            return ['ok' => false, 'message' => $this->cfg->scrub($e->getMessage())];
        }
    }

    /** @return list<array<string, mixed>> */
    private function taxes(): array
    {
        try {
            $d = $this->call('GET', 'v2/gettaxes', []);
        } catch (ConnectorException $e) {
            if (!str_starts_with($e->getMessage(), 'rejected_404')) {
                throw $e;
            }

            $d = $this->call('GET', 'v2/taxes', []); // the manual shows both spellings
        }

        return is_array($d) && array_is_list($d) ? $d : $this->collect($d, 'TaxPct');
    }

    private function taxId(float $rate): string
    {
        $over = $rate == 0.0 ? $this->cfg->str('accountingVatId0') : (abs($rate - 21) < 0.01 ? $this->cfg->str('accountingVatId21') : '');

        if ($over !== '') {
            return $over;
        }

        $ck = 'tax_' . (int) round($rate * 100);
        $cached = $this->cfg->cache('merit', $ck);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        foreach ($this->taxes() as $t) {
            if (!empty($t['NonActive']) || (int) ($t['CountryId'] ?? 0) !== 0) {
                continue;
            }

            if (abs((float) ($t['TaxPct'] ?? -1) - $rate) < 0.001 && !empty($t['Id'])) {
                $this->cfg->cachePut('merit', $ck, (string) $t['Id']);

                return (string) $t['Id'];
            }
        }

        throw new ConnectorException('vat_rate_unmapped_' . $rate, false);
    }

    public function findOrCreateCustomer(array $party): string
    {
        $q = $party['regNo'] !== '' ? ['RegNo' => $party['regNo']] : ['Name' => $party['name']];

        if (!empty($party['general'])) {
            $c = $this->cfg->cache('merit', 'general_customer');

            if (is_string($c) && $c !== '') {
                return $c;
            }
        }

        $d = $this->call('POST', 'v1/getcustomers', $q);

        foreach ($this->collect($d, 'CustomerId') as $row) {
            $nameOk = mb_strtolower(trim((string) ($row['Name'] ?? ''))) === mb_strtolower($party['name']);

            if (($party['regNo'] !== '' && ($row['RegNo'] ?? '') === $party['regNo']) || ($party['regNo'] === '' && $nameOk)) {
                $id = (string) $row['CustomerId'];

                if (!empty($party['general'])) {
                    $this->cfg->cachePut('merit', 'general_customer', $id);
                }

                return $id;
            }
        }

        $new = $this->call('POST', 'v2/sendcustomer', $this->customerPayload($party));
        $id = (string) (is_array($new) ? ($new['Id'] ?? '') : '');

        if ($id === '') {
            throw new ConnectorException('customer_not_created', false);
        }

        if (!empty($party['general'])) {
            $this->cfg->cachePut('merit', 'general_customer', $id);
        }

        return $id;
    }

    private function customerPayload(array $p): array
    {
        return array_filter([
            'Name' => $p['name'],
            'RegNo' => $p['regNo'] ?: null,
            'VatRegNo' => $p['vatNo'] ?: null,
            'NotTDCustomer' => !$p['isCompany'],
            'CountryCode' => 'LV',
            'Address' => $p['address'] ?: null,
            'Email' => $p['email'] ?: null,
        ], fn($v) => $v !== null);
    }

    public function findSale(string $number): ?array
    {
        $d = $this->call('POST', 'v2/getinvoices2', ['InvNo' => $number]);

        foreach ($this->collect($d, 'InvoiceNo') as $row) {
            if ((string) $row['InvoiceNo'] === $number) {
                return ['id' => (string) ($row['SIHId'] ?? ''), 'number' => $number];
            }
        }

        return null;
    }

    public function pushSale(array $doc): array
    {
        $found = $this->findSale($doc['number']);

        if ($found) {
            return ['externalId' => $found['id'], 'externalNo' => $found['number'], 'adopted' => true];
        }

        $tax = $this->taxId((float) $doc['vatRate']);
        $custId = $this->findOrCreateCustomer($doc['party']);
        $day = str_replace('-', '', $doc['date']);
        $due = (new \DateTime($doc['date']))->modify('+' . (int) $doc['dueDays'] . ' days')->format('Ymd');
        $rows = [];

        foreach ($doc['lines'] as $l) {
            $rows[] = [
                'Item' => [
                    'Code' => mb_substr($this->cfg->str($l['kind'] === 'base' ? 'accountingItemCodeBase' : 'accountingItemCodeAddon', $l['kind'] === 'base' ? 'WASH' : 'ADDON'), 0, 20),
                    'Description' => mb_substr($l['name'], 0, 150),
                    'Type' => 2,
                    'UOMName' => 'gab.',
                ],
                'Quantity' => $l['qty'],
                'Price' => $this->money($l['net']),
                'TaxId' => $tax,
            ];
        }

        $payload = [
            'Customer' => ['Id' => $custId],
            'AccountingDoc' => 1,
            'DocDate' => $day,
            'DueDate' => $due,
            'TransactionDate' => $day,
            'InvoiceNo' => $doc['number'],
            'CurrencyCode' => $doc['currency'],
            'InvoiceRow' => $rows,
            'TaxAmount' => [['TaxId' => $tax, 'Amount' => $this->money($doc['vat'])]],
            'RoundingAmount' => 0,
            'TotalAmount' => $this->money($doc['net']),
        ];

        if ($doc['paid']) {
            $pm = $this->cfg->str($doc['method'] === 'Card' ? 'accountingPayMethodCard' : 'accountingPayMethodCash');

            if ($pm === '') {
                throw new ConnectorException('payment_method_not_set', false);
            }

            $payload['Payment'] = ['PaymentMethod' => $pm, 'PaidAmount' => $this->money($doc['gross']), 'PaymDate' => $day . '000000'];
        }

        $d = $this->call('POST', 'v2/sendinvoice', $payload);
        $id = is_array($d) ? (string) ($d['InvoiceId'] ?? '') : '';

        if ($id === '') {
            throw new ConnectorException('invoice_id_missing: ' . $this->snippet(json_encode($d) ?: ''), false);
        }

        return ['externalId' => $id, 'externalNo' => (string) ($d['InvoiceNo'] ?? $doc['number']), 'adopted' => false];
    }

    public function pushPayment(array $doc, string $saleExternalId, float $amount, string $date): array
    {
        $iban = $this->cfg->str('accountingMeritIban');

        if ($iban === '') {
            throw new ConnectorException('payment_account_not_set', false);
        }

        $this->call('POST', 'v1/sendpayment', [
            'IBAN' => $iban,
            'CustomerName' => $doc['party']['name'],
            'InvoiceNo' => $doc['number'],
            'PaymentDate' => str_replace('-', '', $date),
            'Amount' => $this->money($amount),
        ]);

        return ['externalId' => $doc['number']];
    }

    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'host' => $this->cfg->str('accountingMeritHost', 'ee'),
            'vat21' => $this->cfg->str('accountingVatId21') !== '' || $this->cfg->cache('merit', 'tax_2100') !== null,
        ];
    }
}
