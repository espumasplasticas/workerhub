<?php

namespace App\Services\Workers\Orders;

use App\Data\Orders\OrderSiesaStateSnapshot;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

class OrderDomicileBitacoraPreservationService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config
    ) {
    }

    /**
     * Copia las bitacoras del domicilio a notas del pedido antes de desligarlo durante la anulacion.
     *
     * @param array<string, mixed> $payload
     * @param OrderSiesaStateSnapshot $snapshot
     * @return void
     */
    public function preserveDomicileBitacorasAsOrderNotes(array $payload, OrderSiesaStateSnapshot $snapshot): void
    {
        $connection = $this->connectionFor(trim((string) ($payload['db_connection'] ?? '')));
        $order = $this->findLocalOrderWithDomicile($connection, $payload);

        if ($order === null) {
            return;
        }

        $domicileType = trim((string) ($order->PE_DomicilioTipo ?? ''));
        $domicileNumber = (int) ($order->PE_DomicilioNumero ?? 0);
        $orderRowId = (int) ($order->PE_RowId ?? 0);

        if ($domicileType === '' || $domicileNumber <= 0 || $orderRowId <= 0) {
            return;
        }

        $bitacoras = $connection
            ->table($this->movementLogsTable() . ' as movement')
            ->leftJoin($this->intranetUsersTable() . ' as intranet_user', 'intranet_user.US_Id', '=', 'movement.BI_IdUsuario')
            ->where('movement.BI_TipoDocumento', $domicileType)
            ->where('movement.BI_NumeroDocumento', $domicileNumber)
            ->orderBy('movement.BI_Fecha')
            ->get([
                $connection->raw("COALESCE(NULLIF(LTRIM(RTRIM(movement.BI_ResultadoComentario)), ''), NULLIF(LTRIM(RTRIM(movement.BI_Log)), '')) as comment"),
                $connection->raw("COALESCE(NULLIF(LTRIM(RTRIM(intranet_user.US_Nombre)), ''), 'Sistema Logistica') as user_name"),
                'movement.BI_Fecha as created_at',
            ]);

        foreach ($bitacoras as $bitacora) {
            $comment = trim((string) ($bitacora->comment ?? ''));
            $userName = trim((string) ($bitacora->user_name ?? '')) !== ''
                ? trim((string) $bitacora->user_name)
                : 'Sistema Logistica';

            if ($comment === '' || $this->orderNoteAlreadyExists($connection, $orderRowId, $comment, $userName)) {
                continue;
            }

            $connection->table($this->orderNotesTable())->insert([
                'process' => 'Logistica',
                'user_name' => $userName,
                'user_id' => null,
                'type' => 'Bitacora',
                'comment' => $comment,
                'code_store' => 'Logistica',
                'pedido_encabezado_PE_RowId' => $orderRowId,
                'created_at' => $bitacora->created_at,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findLocalOrderWithDomicile(ConnectionInterface $connection, array $payload): ?object
    {
        return $connection->table($this->ordersTable())
            ->select(['PE_RowId', 'PE_DomicilioTipo', 'PE_DomicilioNumero'])
            ->where('PE_CentroOperativo', trim((string) ($payload['operational_center'] ?? '')))
            ->where('PE_TipoDocumento', strtoupper(trim((string) ($payload['document_type'] ?? ''))))
            ->where('PE_NumeroDocumento', (int) ($payload['document_number'] ?? 0))
            ->first();
    }

    private function orderNoteAlreadyExists(ConnectionInterface $connection, int $orderRowId, string $comment, string $userName): bool
    {
        return $connection->table($this->orderNotesTable())
            ->where('pedido_encabezado_PE_RowId', $orderRowId)
            ->where('process', 'Logistica')
            ->where('type', 'Bitacora')
            ->where('comment', $comment)
            ->where('user_name', $userName)
            ->exists();
    }

    private function connectionFor(string $sourceConnection): ConnectionInterface
    {
        $configuredConnections = (array) $this->config->get('workerhub.orders.source_connections', []);
        $connection = (string) ($configuredConnections[$sourceConnection] ?? $sourceConnection);

        return $this->database->connection($connection);
    }

    private function ordersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.orders', 'pos.pedidos_encabezado');
    }

    private function orderNotesTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.order_notes', 'pos.notas_pedidos');
    }

    private function movementLogsTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.movement_logs', 'aplicacion.bitacora_movimientos');
    }

    private function intranetUsersTable(): string
    {
        return (string) $this->config->get('workerhub.orders.tables.intranet_users', 'aplicacion.intranet_usuarios');
    }
}
