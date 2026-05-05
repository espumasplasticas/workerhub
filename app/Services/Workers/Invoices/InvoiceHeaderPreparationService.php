<?php

namespace App\Services\Workers\Invoices;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use stdClass;

class InvoiceHeaderPreparationService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    /**
     * Completa FE_CuentaPorCobrar y FE_ClaseDeCliente cuando la factura legacy
     * aun no los tiene persistidos.
     */
    public function ensureReceivableAndCustomerClassArePresent(array $payload, stdClass $invoiceRecord): bool
    {
        if (!$this->requiresLegacyReceivableCompletion($invoiceRecord)) {
            return false;
        }

        $thirdPartyId = trim((string) ($invoiceRecord->FE_CodigoTercero ?? ''));
        $branchId = trim((string) ($invoiceRecord->FE_CodigoSucursal ?? ''));

        if ($thirdPartyId === '') {
            return false;
        }

        $sourceDatabase = $this->database->connection($this->resolveSourceConnectionName($payload));
        $clientRecord = $sourceDatabase
            ->table($this->customersTable())
            ->select(['CL_ClaseDeCliente'])
            ->where('CL_CodigoTercero', $thirdPartyId)
            ->when($branchId !== '', fn ($query) => $query->where('CL_Sucursal', $branchId))
            ->first();

        if (!$clientRecord instanceof stdClass) {
            return false;
        }

        $customerClassId = trim((string) ($clientRecord->CL_ClaseDeCliente ?? ''));

        if ($customerClassId === '') {
            return false;
        }

        $customerClassRecord = $sourceDatabase
            ->table($this->customerClassesTable())
            ->select(['CC_CuentaPorCobrar'])
            ->where('CC_Id', $customerClassId)
            ->first();

        $receivableAccount = trim((string) ($customerClassRecord?->CC_CuentaPorCobrar ?? ''));

        if ($receivableAccount === '') {
            return false;
        }

        $sourceDatabase
            ->table($this->invoicesTable())
            ->where('FE_CentroOperativo', trim((string) ($invoiceRecord->FE_CentroOperativo ?? '')))
            ->where('FE_TipoDocumento', trim((string) ($invoiceRecord->FE_TipoDocumento ?? '')))
            ->where('FE_NumeroDocumento', trim((string) ($invoiceRecord->FE_NumeroDocumento ?? '')))
            ->update([
                'FE_CuentaPorCobrar' => $receivableAccount,
                'FE_ClaseDeCliente' => $customerClassId,
            ]);

        return true;
    }

    private function requiresLegacyReceivableCompletion(stdClass $invoiceRecord): bool
    {
        return trim((string) ($invoiceRecord->FE_CuentaPorCobrar ?? '')) === '';
    }

    private function resolveSourceConnectionName(array $payload): string
    {
        $dbConnection = trim((string) ($payload['db_connection'] ?? 'sqlsrv'));

        return (string) $this->config->get(sprintf('workerhub.invoices.source_connections.%s', $dbConnection), 'source_sqlsrv');
    }

    private function invoicesTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.tables.invoices', 'pos.facturas_encabezado');
    }

    private function customersTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.tables.customers', 'pos.clientes');
    }

    private function customerClassesTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.tables.customer_classes', 'pos.clase_de_cliente');
    }
}
