<?php

namespace App\Services\Workers\Orders;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class OrderCashConversionService
{
    private const THIRD_PARTY_ROW_ID_SQL = <<<'SQL'
SELECT TOP 1 f200_rowid AS rowid_tercero
FROM SiesaEnterprise.dbo.t200_mm_terceros
WHERE f200_id = ?
  AND f200_id_cia = 1
SQL;

    private const FAST_SUPPORTED_AMOUNT_SQL = <<<'SQL'
SELECT SUM(t350.f350_total_db) AS total_supported_amount
FROM SiesaEnterprise.dbo.t353_co_saldo_abierto AS t353
INNER JOIN SiesaEnterprise.dbo.t253_co_auxiliares AS t253
    ON t353.f353_rowid_auxiliar = t253.f253_rowid
    AND t353.f353_id_cia = t253.f253_id_cia
INNER JOIN SiesaEnterprise.dbo.t350_co_docto_contable AS t350
    ON t353.f353_rowid_docto = t350.f350_rowid
    AND t353.f353_id_cia = t350.f350_id_cia
WHERE t353.f353_id_cia = 1
  AND t353.f353_rowid_tercero = ?
  AND t353.f353_id_sucursal = ?
  AND t350.f350_ind_estado = 1
  AND (t353.f353_total_db - t353.f353_total_cr <> 0)
  AND t253.f253_notas LIKE '%BALANCE%'
  AND t253.f253_id NOT IN ('28050558', '13050550')
  AND EXISTS (
      SELECT 1
      FROM SiesaEnterprise.dbo.t201_mm_clientes AS t201
      WHERE t201.f201_rowid_tercero = ?
        AND t201.f201_id_sucursal = ?
        AND t201.f201_id_cia = 1
  )
SQL;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {
    }

    public function normalizeIfSupported(array $payload, stdClass $header, ?stdClass $orderRecord = null): bool
    {
        if ($this->isAlreadyCashOrder($orderRecord)) {
            return false;
        }

        if (trim((string) ($header->f430_id_cond_pago ?? '')) !== '001') {
            return false;
        }

        $enterpriseOperationalCenter = trim((string) ($header->SA_CentroOperativoEnterprise ?? ''));
        if ($enterpriseOperationalCenter === '') {
            return false;
        }

        $connection = $this->connectionFor((string) ($payload['db_connection'] ?? ''));
        if (!$this->hasCashRegister999($connection, $enterpriseOperationalCenter)) {
            return false;
        }

        $supportedAmount = $this->supportedPaymentAmount(
            $connection,
            trim((string) ($header->f430_id_tercero_fact ?? '')),
            trim((string) ($header->f430_id_sucursal_fact ?? ''))
        );

        $legacyNetTotal = (float) ($header->PE_TotalNeto ?? 0);
        $difference = $supportedAmount - $legacyNetTotal;

        if ($supportedAmount <= 0 || $difference < -1000) {
            return false;
        }

        $this->orderQuery($connection, $payload)->update([
            'PE_FormaDePago' => 0,
            'PE_CondicionDePago' => '0',
        ]);

        return true;
    }

    private function isAlreadyCashOrder(?stdClass $orderRecord): bool
    {
        if (!$orderRecord instanceof stdClass) {
            return false;
        }

        $paymentMethod = trim((string) ($orderRecord->PE_FormaDePago ?? ''));
        $paymentCondition = trim((string) ($orderRecord->PE_CondicionDePago ?? ''));

        return $paymentMethod === '0' && $paymentCondition === '0';
    }

    private function hasCashRegister999(ConnectionInterface $connection, string $enterpriseOperationalCenter): bool
    {
        $count = $connection->table($this->cashRegisterTable())
            ->where('f291_id_co', $enterpriseOperationalCenter)
            ->where('f291_id', '999')
            ->where('f291_id_cia', 1)
            ->count();

        return $count === 1;
    }

    private function supportedPaymentAmount(ConnectionInterface $connection, string $thirdPartyId, string $branchId): float
    {
        if ($thirdPartyId === '' || $branchId === '') {
            return 0.0;
        }

        return $this->supportedPaymentAmountEquivalentToLegacy($connection, $thirdPartyId, $branchId);
    }

    /**
     * Replica solo la primera agregacion del legacy (@total_pago).
     * El detalle por medio de pago del SP no se usa en el worker y solo agrega latencia.
     */
    private function supportedPaymentAmountEquivalentToLegacy(ConnectionInterface $connection, string $thirdPartyId, string $branchId): float
    {
        if (!$this->shouldUseFastSupportedAmountQuery()) {
            return 0.0;
        }

        $thirdPartyRowId = $this->findThirdPartyRowId($connection, $thirdPartyId);
        if ($thirdPartyRowId <= 0) {
            return 0.0;
        }

        $row = $connection->selectOne(self::FAST_SUPPORTED_AMOUNT_SQL, [
            $thirdPartyRowId,
            $branchId,
            $thirdPartyRowId,
            $branchId,
        ]);

        return (float) ($row->total_supported_amount ?? 0);
    }

    private function findThirdPartyRowId(ConnectionInterface $connection, string $thirdPartyId): int
    {
        if ($thirdPartyId === '') {
            return 0;
        }

        $row = $connection->selectOne(self::THIRD_PARTY_ROW_ID_SQL, [$thirdPartyId]);

        return (int) ($row->rowid_tercero ?? 0);
    }

    private function orderQuery(ConnectionInterface $connection, array $payload): \Illuminate\Database\Query\Builder
    {
        return $connection
            ->table($this->ordersTable())
            ->where('PE_CentroOperativo', trim((string) ($payload['operational_center'] ?? '')))
            ->where('PE_TipoDocumento', trim((string) ($payload['document_type'] ?? '')))
            ->where('PE_NumeroDocumento', trim((string) ($payload['document_number'] ?? '')));
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        return $this->database->connection($connection);
    }

    private function cashRegisterTable(): string
    {
        return (string) $this->config->get('workerhub.orders.cash_conversion.cash_registers_table', 'SiesaEnterprise.dbo.t291_co_cajas');
    }

    private function shouldUseFastSupportedAmountQuery(): bool
    {
        return (bool) $this->config->get('workerhub.orders.cash_conversion.fast_supported_amount_query', true);
    }

    private function ordersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.orders', 'pos.pedidos_encabezado');
    }
}
