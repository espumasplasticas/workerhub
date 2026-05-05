<?php

namespace App\Services\Workers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use stdClass;

class DocumentImportAttemptControlService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    /**
     * Registra un intento real de importacion de pedido justo antes del envio a Siesa.
     */
    public function registerOrderMigrationAttempt(array $payload): void
    {
        $this->registerDocumentAttempt(
            $this->resolveOrderSourceConnectionName($payload),
            trim((string) ($payload['operational_center'] ?? '')),
            trim((string) ($payload['document_type'] ?? '')),
            trim((string) ($payload['document_number'] ?? ''))
        );
    }

    /**
     * Registra un intento real de importacion de recibo justo antes del envio a Siesa.
     */
    public function registerReceiptMigrationAttempt(array $payload): void
    {
        $this->registerDocumentAttempt(
            $this->resolveReceiptSourceConnectionName($payload),
            trim((string) ($payload['operational_center'] ?? '')),
            trim((string) ($payload['document_type'] ?? '')),
            trim((string) ($payload['document_number'] ?? ''))
        );
    }

    /**
     * Registra el intento real de importacion de factura y devuelve el numero
     * de intento resultante segun el ciclo legacy de 1..28.
     */
    public function registerInvoiceMigrationFailureAttemptAndReturnAttemptNumber(array $payload): int
    {
        return $this->registerCycledDocumentAttemptAndReturnAttemptNumber(
            $this->resolveInvoiceSourceConnectionName($payload),
            trim((string) ($payload['operational_center'] ?? '')),
            trim((string) ($payload['document_type'] ?? '')),
            trim((string) ($payload['document_number'] ?? '')),
            $this->invoiceAttemptCycleLimit()
        );
    }

    /**
     * Registra los terceros preparados dentro del customer sync de pedidos.
     *
     * @param array<string, mixed> $customerSync
     */
    public function registerPreparedOrderCustomerAttempts(array $payload, array $customerSync): void
    {
        $this->registerPreparedCustomerAttempts(
            $this->resolveOrderSourceConnectionName($payload),
            trim((string) ($payload['operational_center'] ?? '')),
            $customerSync
        );
    }

    /**
     * Registra los terceros preparados dentro del customer sync de recibos.
     *
     * @param array<string, mixed> $customerSync
     */
    public function registerPreparedReceiptCustomerAttempts(array $payload, array $customerSync): void
    {
        $this->registerPreparedCustomerAttempts(
            $this->resolveReceiptSourceConnectionName($payload),
            trim((string) ($payload['operational_center'] ?? '')),
            $customerSync
        );
    }

    /**
     * Registra los terceros preparados dentro del customer sync de facturas.
     *
     * @param array<string, mixed> $customerSync
     */
    public function registerPreparedInvoiceCustomerAttempts(array $payload, array $customerSync): void
    {
        $this->registerPreparedCustomerAttempts(
            $this->resolveInvoiceSourceConnectionName($payload),
            trim((string) ($payload['operational_center'] ?? '')),
            $customerSync
        );
    }

    /**
     * @param array<string, mixed> $customerSync
     */
    private function registerPreparedCustomerAttempts(string $connectionName, string $operationalCenter, array $customerSync): void
    {
        $preparedCustomers = [];

        foreach ((array) ($customerSync['parties'] ?? []) as $party) {
            if (!is_array($party) || ($party['status'] ?? null) !== 'prepared') {
                continue;
            }

            $snapshot = is_array($party['snapshot'] ?? null) ? $party['snapshot'] : [];
            $thirdPartyId = trim((string) ($snapshot['third_party_id'] ?? ''));
            $sourceBranch = trim((string) ($snapshot['source_branch'] ?? ''));

            if ($thirdPartyId === '' || $sourceBranch === '') {
                continue;
            }

            $preparedCustomers[$thirdPartyId . '|' . $sourceBranch] = [
                'third_party_id' => $thirdPartyId,
                'source_branch' => $sourceBranch,
            ];
        }

        foreach ($preparedCustomers as $preparedCustomer) {
            $this->registerDocumentAttempt(
                $connectionName,
                $operationalCenter,
                $this->customerAttemptDocumentType(),
                $preparedCustomer['third_party_id'] . '-' . $preparedCustomer['source_branch']
            );
        }
    }

    private function registerDocumentAttempt(
        string $connectionName,
        string $operationalCenter,
        string $documentType,
        string $documentNumber
    ): void {
        if ($operationalCenter === '' || $documentType === '' || $documentNumber === '') {
            return;
        }

        $timestamp = now()->format('Y-m-d H:i:s');

        $this->database->connection($connectionName)->affectingStatement(
            sprintf(
                "MERGE %s AS target
                USING (
                    SELECT
                        ? AS DC_centro_operativo,
                        ? AS DC_tipo_documento,
                        ? AS DC_numero_documento
                ) AS source
                    ON target.DC_centro_operativo = source.DC_centro_operativo
                    AND target.DC_tipo_documento = source.DC_tipo_documento
                    AND target.DC_numero_documento = source.DC_numero_documento
                WHEN MATCHED THEN
                    UPDATE SET
                        DC_intentos = ISNULL(target.DC_intentos, 0) + 1,
                        DC_fecha = ?
                WHEN NOT MATCHED THEN
                    INSERT (
                        DC_centro_operativo,
                        DC_tipo_documento,
                        DC_numero_documento,
                        DC_intentos,
                        DC_fecha
                    )
                    VALUES (?, ?, ?, 1, ?);",
                $this->documentImportControlTable()
            ),
            [
                $operationalCenter,
                $documentType,
                $documentNumber,
                $timestamp,
                $operationalCenter,
                $documentType,
                $documentNumber,
                $timestamp,
            ]
        );
    }

    private function registerCycledDocumentAttemptAndReturnAttemptNumber(
        string $connectionName,
        string $operationalCenter,
        string $documentType,
        string $documentNumber,
        int $cycleLimit
    ): int {
        if ($operationalCenter === '' || $documentType === '' || $documentNumber === '') {
            return 0;
        }

        $timestamp = now()->format('Y-m-d H:i:s');
        $record = $this->database->connection($connectionName)->selectOne(
            sprintf(
                "MERGE %s AS target
                USING (
                    SELECT
                        ? AS DC_centro_operativo,
                        ? AS DC_tipo_documento,
                        ? AS DC_numero_documento
                ) AS source
                    ON target.DC_centro_operativo = source.DC_centro_operativo
                    AND target.DC_tipo_documento = source.DC_tipo_documento
                    AND target.DC_numero_documento = source.DC_numero_documento
                WHEN MATCHED THEN
                    UPDATE SET
                        DC_intentos = CASE
                            WHEN ISNULL(target.DC_intentos, 0) >= ? THEN 1
                            ELSE ISNULL(target.DC_intentos, 0) + 1
                        END,
                        DC_fecha = ?
                WHEN NOT MATCHED THEN
                    INSERT (
                        DC_centro_operativo,
                        DC_tipo_documento,
                        DC_numero_documento,
                        DC_intentos,
                        DC_fecha
                    )
                    VALUES (?, ?, ?, 1, ?)
                OUTPUT INSERTED.DC_intentos AS current_attempt;",
                $this->documentImportControlTable()
            ),
            [
                $operationalCenter,
                $documentType,
                $documentNumber,
                $cycleLimit,
                $timestamp,
                $operationalCenter,
                $documentType,
                $documentNumber,
                $timestamp,
            ]
        );

        return $record instanceof stdClass && is_numeric($record->current_attempt ?? null)
            ? (int) $record->current_attempt
            : 0;
    }

    private function documentImportControlTable(): string
    {
        return (string) $this->config->get('workerhub.import_attempt_control.table', 'pos.control_importacion_documentos');
    }

    private function customerAttemptDocumentType(): string
    {
        return trim((string) $this->config->get('workerhub.import_attempt_control.customer_document_type', 'CLI'));
    }

    private function invoiceAttemptCycleLimit(): int
    {
        return (int) $this->config->get('workerhub.invoices.import_attempts.cycle_limit', 28);
    }

    private function resolveOrderSourceConnectionName(array $payload): string
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? 'sqlsrv'));

        return (string) $this->config->get(sprintf('workerhub.orders.source_connections.%s', $dbConnection), 'source_sqlsrv');
    }

    private function resolveReceiptSourceConnectionName(array $payload): string
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? 'sqlsrv'));

        return (string) $this->config->get(sprintf('workerhub.receipts.source_connections.%s', $dbConnection), 'source_sqlsrv');
    }

    private function resolveInvoiceSourceConnectionName(array $payload): string
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? 'sqlsrv'));

        return (string) $this->config->get(sprintf('workerhub.invoices.source_connections.%s', $dbConnection), 'source_sqlsrv');
    }
}
