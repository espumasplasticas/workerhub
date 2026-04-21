<?php

namespace App\Services\Workers\Orders;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

class OrderCashConversionService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {
    }

    public function normalizeIfSupported(array $payload, stdClass $header): bool
    {
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

        $rows = $connection->select(
            sprintf('EXEC %s ?, ?', $this->supportedPaymentsProcedure()),
            [$thirdPartyId, $branchId]
        );

        $total = 0.0;

        foreach ($rows as $row) {
            $total += (float) ($row->valor_saldo_cruzar ?? 0);
        }

        return $total;
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

    private function supportedPaymentsProcedure(): string
    {
        return (string) $this->config->get('workerhub.orders.cash_conversion.supported_payments_procedure', 'ventas.usp_obtener_medidos_pago_del_valor_que_soporta_la_venta_V2');
    }

    private function ordersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.orders', 'pos.pedidos_encabezado');
    }
}
