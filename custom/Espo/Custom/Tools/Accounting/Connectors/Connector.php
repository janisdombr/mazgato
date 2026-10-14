<?php

namespace Espo\Custom\Tools\Accounting\Connectors;

use Espo\Custom\Tools\Accounting\ConnectorException;

/**
 * One accounting program. A connector knows ONLY how to talk to that program; which orders are sent, when, how often and what
 * happens after a failure is the SyncService's job. A connector never touches the database of the CRM.
 *
 * A sale document ($doc) is built by SaleBuilder: number, date (Y-m-d), currency, party, lines [kind,name,qty,net,vat,gross],
 * net, vat, gross, vatRate, paid, method (Cash|Card|Invoice), dueDays, hash.
 */
interface Connector
{
    /** Stable id stored on the ledger rows ("merit", "smartaccounts"). */
    public function code(): string;

    public function label(): string;

    /** Credentials present (no network). */
    public function isConfigured(): bool;

    /** One cheap authenticated read. @return array{ok: bool, message: string, details?: array<string, mixed>} */
    public function testConnection(): array;

    /** The program's id of this customer (found by reg. no. / name, or created). @throws ConnectorException */
    public function findOrCreateCustomer(array $party): string;

    /** An invoice already booked under this number, if any: ['id' => , 'number' => ] - the idempotency probe. @throws ConnectorException */
    public function findSale(string $number): ?array;

    /**
     * Book the sale. Idempotent by the document number: if the program already has that number, it is ADOPTED
     * (`adopted: true`), nothing is created twice. @return array{externalId: string, externalNo: string, adopted: bool}
     * @throws ConnectorException
     */
    public function pushSale(array $doc): array;

    /**
     * Register a payment against an already booked sale (used when the order is paid AFTER the sale was sent).
     * @return array{externalId: string}
     * @throws ConnectorException
     */
    public function pushPayment(array $doc, string $saleExternalId, float $amount, string $date): array;

    /** Non-secret facts for the admin page: configured, mapping found, etc. @return array<string, mixed> */
    public function status(): array;
}
