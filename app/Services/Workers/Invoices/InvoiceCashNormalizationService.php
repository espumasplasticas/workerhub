<?php

namespace App\Services\Workers\Invoices;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use stdClass;

class InvoiceCashNormalizationService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    /**
     * Replica la conversion legacy de credito 001 a contado cuando existen
     * medios de pago suficientes para soportar la factura.
     */
    public function normalizeIfSupported(array $payload, stdClass $invoiceHeader): bool
    {
        if (trim((string) ($invoiceHeader->f461_id_cond_pago ?? '')) !== '001') {
            return false;
        }

        $sourceConnection = $this->resolveSourceConnectionName($payload);
        $sourceDatabase = $this->database->connection($sourceConnection);

        $existingPaymentCount = (int) $sourceDatabase
            ->table($this->paymentsTable())
            ->where('FP_CentroOperativo', trim((string) ($invoiceHeader->F350_ID_CO ?? '')))
            ->where('FP_TipoDocumento', trim((string) ($invoiceHeader->F350_ID_TIPO_DOCTO ?? '')))
            ->where('FP_NumeroDocumento', trim((string) ($invoiceHeader->F350_CONSEC_DOCTO ?? '')))
            ->count();

        if ($existingPaymentCount > 0) {
            return false;
        }

        $cashRegisterCount = $sourceDatabase->selectOne(
            sprintf(
                'SELECT COUNT(f291_id) AS total_registers
                FROM %s
                WHERE f291_id_co = ?
                  AND f291_id = ?
                  AND f291_id_cia = 1',
                $this->cashRegistersTable()
            ),
            [
                trim((string) ($invoiceHeader->F350_ID_CO ?? '')),
                $this->normalizedCashRegisterId(),
            ]
        );
        $cashRegisterExists = $cashRegisterCount instanceof stdClass
            && (int) ($cashRegisterCount->total_registers ?? 0) > 0;

        if (!$cashRegisterExists) {
            return false;
        }

        $supportedPayments = $sourceDatabase->select(
            sprintf(
                'EXEC %s ?, ?',
                $this->supportedPaymentsProcedure()
            ),
            [
                trim((string) ($invoiceHeader->F350_ID_TERCERO ?? '')),
                trim((string) ($invoiceHeader->f461_id_sucursal_fact ?? '')),
            ]
        );

        if ($supportedPayments === []) {
            return false;
        }

        $supportedAmount = 0.0;

        foreach ($supportedPayments as $supportedPayment) {
            if (!$supportedPayment instanceof stdClass) {
                continue;
            }

            $supportedAmount += (float) ($supportedPayment->valor_medio_pago ?? 0);
        }

        $difference = $supportedAmount - (float) ($invoiceHeader->FE_TotalNeto ?? 0);

        if ($difference < $this->minimumSupportedAmountDifference()) {
            return false;
        }

        $sourceDatabase
            ->table($this->invoicesTable())
            ->where('FE_CentroOperativo', trim((string) ($invoiceHeader->F350_ID_CO ?? '')))
            ->where('FE_TipoDocumento', trim((string) ($invoiceHeader->F350_ID_TIPO_DOCTO ?? '')))
            ->where('FE_NumeroDocumento', trim((string) ($invoiceHeader->F350_CONSEC_DOCTO ?? '')))
            ->update([
                'FE_FormaDePago' => 0,
                'FE_CondicionDePago' => '0',
            ]);

        $remainingNetTotal = (float) ($invoiceHeader->FE_TotalNeto ?? 0);

        foreach ($supportedPayments as $supportedPayment) {
            if (!$supportedPayment instanceof stdClass || $remainingNetTotal <= 0) {
                continue;
            }

            $supportedPaymentValue = (float) ($supportedPayment->valor_medio_pago ?? 0);

            if ($remainingNetTotal >= $supportedPaymentValue) {
                $paymentValue = $supportedPaymentValue;
                $remainingNetTotal -= $supportedPaymentValue;
            } else {
                $paymentValue = $remainingNetTotal;
                $remainingNetTotal = 0;
            }

            if ($paymentValue > 0 && $remainingNetTotal >= 1 && $remainingNetTotal <= 1000) {
                $paymentValue += $remainingNetTotal;
                $remainingNetTotal = 0;
            }

            $sourceDatabase->table($this->paymentsTable())->insert([
                'FP_CentroOperativo' => trim((string) ($invoiceHeader->F350_ID_CO ?? '')),
                'FP_TipoDocumento' => trim((string) ($invoiceHeader->F350_ID_TIPO_DOCTO ?? '')),
                'FP_NumeroDocumento' => trim((string) ($invoiceHeader->F350_CONSEC_DOCTO ?? '')),
                'FP_MedioDePago' => $supportedPayment->id_medio_pago_pos ?? null,
                'FP_Autorizacion' => $supportedPayment->nro_autorizacion ?? null,
                'FP_Valor' => $paymentValue,
                'FP_Detalle' => sprintf(
                    '%s-%s-%s',
                    trim((string) ($invoiceHeader->F350_ID_CO ?? '')),
                    trim((string) ($invoiceHeader->F350_ID_TIPO_DOCTO ?? '')),
                    trim((string) ($invoiceHeader->F350_CONSEC_DOCTO ?? ''))
                ),
            ]);
        }

        return true;
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

    private function paymentsTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.tables.payments', 'pos.facturas_pagos');
    }

    private function cashRegistersTable(): string
    {
        return (string) $this->config->get('workerhub.invoices.cash_normalization.cash_registers_table', 'SiesaEnterprise.dbo.t291_co_cajas');
    }

    private function normalizedCashRegisterId(): string
    {
        return trim((string) $this->config->get('workerhub.invoices.cash_normalization.cash_register_id', '999'));
    }

    private function supportedPaymentsProcedure(): string
    {
        return (string) $this->config->get(
            'workerhub.invoices.cash_normalization.supported_payments_procedure',
            'ventas.usp_obtener_medidos_pago_del_valor_que_soporta_la_venta_V2'
        );
    }

    private function minimumSupportedAmountDifference(): float
    {
        return (float) $this->config->get('workerhub.invoices.cash_normalization.minimum_supported_amount_difference', -1000);
    }
}
