<?php

namespace Espo\Custom\Tools\Accounting\Connectors;

use Espo\Custom\Tools\Accounting\ConnectorException;
use Espo\Custom\Tools\Accounting\Http;

/**
 * SmartAccounts (Estonia). Source: "SmartAccounts API" specification v1.7 (28.04.2025),
 * https://www.smartaccounts.eu/uuskodu2015/wp-content/uploads/2025/04/SmartAccounts_API_latest.pdf (read 2026-10-12).
 *
 * Documented: JSON over HTTPS at https://sa.smartaccounts.eu/api/; URL parameters timestamp (ddMMyyyyHHmmss, Estonian time,
 * +-15 min), apikey (public key), signature = HEX(HMAC-SHA256(all URL parameters before "signature" + body, secret key)),
 * body without line breaks, UTF-8; 60 requests/minute and 1000 per 24 h (503 over the limit, 503 also on a billing problem);
 * POST purchasesales/clientinvoices:add (invoiceNumber optional, paymentMethod + paymentAmount book the payment with the invoice),
 * clients:get / :add, articles:get / :add, settings/vatpcs:get, purchasesales/payments:add. The spec asks developers to build
 * against a TEST environment obtained from SmartAccounts support: live bookings cannot be rolled back.
 *
 * NOT verified (spec silent): the wrapper keys of GET answers (so lists are searched structurally), whether row `price` is
 * net (assumed: the row has separate `vat` and `sum` with VAT), the exact error body, whether a Latvian company can use
 * 21 % (custom VAT percentages exist), the account / payment method names of the owner's company.
 */
class SmartAccountsConnector extends BaseConnector
{
    public function code(): string
    {
        return 'smartaccounts';
    }

    public function label(): string
    {
        return 'SmartAccounts';
    }

    public function isConfigured(): bool
    {
        return $this->cfg->str('accountingSmartId') !== '' && $this->cfg->secret('accountingSmartKey')->isSet();
    }

    private function base(): string
    {
        $t = $this->cfg->testBase();

        return ($t ?? 'https://sa.smartaccounts.eu') . '/api/';
    }

    /**
     * @param array<string, string> $query extra URL parameters
     * @return mixed decoded answer
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $pairs = [];

        foreach ($query as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
        }

        $pairs[] = 'timestamp=' . (new \DateTime('now', new \DateTimeZone('Europe/Tallinn')))->format('dmYHis');
        $pairs[] = 'apikey=' . rawurlencode($this->cfg->str('accountingSmartId'));
        $qs = implode('&', $pairs);
        $json = $body === null ? '' : (json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $sig = hash_hmac('sha256', $qs . $json, $this->cfg->secret('accountingSmartKey')->reveal());
        $r = Http::send($method, $this->base() . $path . '?' . $qs . '&signature=' . $sig, ['Content-Type: application/json; charset=UTF-8', 'Accept: application/json'], $body === null ? null : $json);
        $this->check($r);
        $d = $this->json($r);

        if (is_array($d) && (isset($d['errorCode']) || isset($d['errors']) || isset($d['error']) || (isset($d['status']) && is_string($d['status']) && stripos($d['status'], 'error') !== false))) {
            throw new ConnectorException('rejected: ' . $this->snippet(json_encode($d) ?: ''), false);
        }

        return $d;
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        try {
            $v = $this->vatPcs();

            return ['ok' => true, 'message' => 'ok', 'details' => ['vatPcs' => count($v)]];
        } catch (ConnectorException $e) {
            return ['ok' => false, 'message' => $this->cfg->scrub($e->getMessage())];
        }
    }

    /** @return list<array<string, mixed>> */
    private function vatPcs(): array
    {
        return $this->collect($this->call('GET', 'settings/vatpcs:get'), 'vatPc');
    }

    private function vatCode(float $rate): string
    {
        $over = $rate == 0.0 ? $this->cfg->str('accountingVatId0') : (abs($rate - 21) < 0.01 ? $this->cfg->str('accountingVatId21') : '');

        if ($over !== '') {
            return $over;
        }

        $ck = 'vat_' . (int) round($rate * 100);
        $cached = $this->cfg->cache('smartaccounts', $ck);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        foreach ($this->vatPcs() as $v) {
            if (array_key_exists('activeSales', $v) && !$v['activeSales']) {
                continue;
            }

            if (abs((float) ($v['pc'] ?? -1) - $rate) < 0.001 && ($v['vatPc'] ?? '') !== '') {
                $this->cfg->cachePut('smartaccounts', $ck, (string) $v['vatPc']);

                return (string) $v['vatPc'];
            }
        }

        throw new ConnectorException('vat_rate_unmapped_' . $rate, false);
    }

    public function findOrCreateCustomer(array $party): string
    {
        if (!empty($party['general'])) {
            $c = $this->cfg->cache('smartaccounts', 'general_customer');

            if (is_string($c) && $c !== '') {
                return $c;
            }
        }

        $needle = $party['regNo'] !== '' ? $party['regNo'] : $party['name'];

        foreach ($this->collect($this->call('GET', 'purchasesales/clients:get', ['nameOrRegCode' => $needle]), 'id') as $row) {
            $hit = $party['regNo'] !== '' ? ($row['regCode'] ?? '') === $party['regNo']
                : mb_strtolower(trim((string) ($row['name'] ?? ''))) === mb_strtolower($party['name']);

            if ($hit) {
                $id = (string) $row['id'];

                if (!empty($party['general'])) {
                    $this->cfg->cachePut('smartaccounts', 'general_customer', $id);
                }

                return $id;
            }
        }

        $body = array_filter([
            'name' => $party['name'],
            'regCode' => $party['regNo'] ?: null,
            'vatNumber' => $party['vatNo'] ?: null,
            'address' => $party['address'] !== '' ? ['country' => 'LV', 'address1' => $party['address']] : ['country' => 'LV'],
            'contacts' => $party['email'] !== '' ? [['type' => 'EMAIL', 'value' => $party['email']]] : null,
        ], fn($v) => $v !== null);
        $d = $this->call('POST', 'purchasesales/clients:add', [], $body);
        $id = is_array($d) ? (string) ($d['clientId'] ?? '') : '';

        if ($id === '') {
            throw new ConnectorException('customer_not_created', false);
        }

        if (!empty($party['general'])) {
            $this->cfg->cachePut('smartaccounts', 'general_customer', $id);
        }

        return $id;
    }

    public function findSale(string $number): ?array
    {
        $d = $this->call('GET', 'purchasesales/clientinvoices:get', ['invoiceNumber' => $number]);

        foreach ($this->collect($d, 'invoiceNumber') as $row) {
            if ((string) $row['invoiceNumber'] === $number && !empty($row['id'])) {
                return ['id' => (string) $row['id'], 'number' => $number];
            }
        }

        return null;
    }

    private function ensureArticle(string $code, string $description, string $vatPc): void
    {
        if ($this->cfg->cache('smartaccounts', 'article_' . $code) === true) {
            return;
        }

        $d = $this->call('GET', 'purchasesales/articles:get', ['code' => $code]);
        $have = false;

        foreach ($this->collect($d, 'code') as $a) {
            if ((string) $a['code'] === $code) {
                $have = true;
            }
        }

        if (!$have) {
            $this->call('POST', 'purchasesales/articles:add', [], [
                'code' => $code, 'description' => $description, 'type' => 'SERVICE', 'unit' => 'gab.', 'vatPc' => $vatPc, 'activeSales' => true,
            ]);
        }

        $this->cfg->cachePut('smartaccounts', 'article_' . $code, true);
    }

    public function pushSale(array $doc): array
    {
        $found = $this->findSale($doc['number']);

        if ($found) {
            return ['externalId' => $found['id'], 'externalNo' => $found['number'], 'adopted' => true];
        }

        $vat = $this->vatCode((float) $doc['vatRate']);
        $client = $this->findOrCreateCustomer($doc['party']);
        $day = (new \DateTime($doc['date']));
        $rows = [];

        foreach ($doc['lines'] as $l) {
            $code = mb_substr($this->cfg->str($l['kind'] === 'base' ? 'accountingItemCodeBase' : 'accountingItemCodeAddon', $l['kind'] === 'base' ? 'WASH' : 'ADDON'), 0, 20);
            $this->ensureArticle($code, $l['kind'] === 'base' ? 'Car wash' : 'Car wash add-on', $vat);
            $rows[] = ['code' => $code, 'description' => $l['name'], 'price' => $this->money($l['net']), 'quantity' => $l['qty'], 'vatPc' => $vat];
        }

        $body = [
            'clientId' => $client,
            'date' => $day->format('d.m.Y'),
            'dueDate' => (clone $day)->modify('+' . (int) $doc['dueDays'] . ' days')->format('d.m.Y'),
            'currency' => $doc['currency'],
            'invoiceNumber' => $doc['number'],
            'totalAmount' => $this->money($doc['gross']), // SmartAccounts puts any cent difference into roundAmount
            'rows' => $rows,
        ];

        if ($doc['paid']) {
            $pm = $this->cfg->str($doc['method'] === 'Card' ? 'accountingPayMethodCard' : 'accountingPayMethodCash');

            if ($pm === '') {
                throw new ConnectorException('payment_method_not_set', false);
            }

            $body['paymentMethod'] = $pm;
            $body['paymentAmount'] = $this->money($doc['gross']);
        }

        $d = $this->call('POST', 'purchasesales/clientinvoices:add', [], $body);
        $id = is_array($d) ? (string) ($d['invoiceId'] ?? '') : '';

        if ($id === '') {
            throw new ConnectorException('invoice_id_missing: ' . $this->snippet(json_encode($d) ?: ''), false);
        }

        return ['externalId' => $id, 'externalNo' => (string) ($d['invoiceNumber'] ?? $doc['number']), 'adopted' => false];
    }

    public function pushPayment(array $doc, string $saleExternalId, float $amount, string $date): array
    {
        $cash = ($doc['method'] ?? '') !== 'Card';
        $account = $this->cfg->str($cash ? 'accountingPayAccountCash' : 'accountingPayAccountCard');

        if ($account === '') {
            throw new ConnectorException('payment_account_not_set', false);
        }

        $client = $this->findOrCreateCustomer($doc['party']);
        $d = $this->call('POST', 'purchasesales/payments:add', [], [
            'date' => (new \DateTime($date))->format('d.m.Y'),
            'partnerType' => 'CLIENT',
            'clientId' => $client,
            'accountType' => $cash ? 'CASH' : 'BANK',
            'accountName' => $account,
            'currency' => 'EUR',
            'amount' => $this->money($amount),
            'rows' => [['type' => 'CLIENT_INVOICE', 'id' => $saleExternalId, 'amount' => $this->money($amount)]],
        ]);

        return ['externalId' => is_array($d) ? (string) ($d['paymentId'] ?? '') : ''];
    }

    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'vat21' => $this->cfg->str('accountingVatId21') !== '' || $this->cfg->cache('smartaccounts', 'vat_2100') !== null,
        ];
    }
}
