<?php

namespace App\Services\Workers\Orders;

use App\Exceptions\WorkerTaskProcessingException;
use stdClass;

class OrderPreMigrationGuard
{
    public function __construct(private readonly OrderPrototypeRepository $repository)
    {
    }

    /**
     * @return array<string, scalar|bool|null>
     */
    public function assertCanMigrate(array $payload, ?stdClass $order = null): array
    {
        $order ??= $this->repository->findOrderRecord($payload);

        $clientCode = trim((string) ($order->PE_CodigoTercero ?? ''));
        $clientBranch = trim((string) ($order->PE_CodigoSucursal ?? ''));
        $printed = (int) ($order->PE_IndicadorImpreso ?? 0) === 1;
        $customerClass = trim((string) ($order->PE_ClaseDeCliente ?? ''));

        if ($clientCode === '') {
            throw new WorkerTaskProcessingException('El pedido no tiene cliente asociado para migrar.', ['payload' => $payload]);
        }

        if ($customerClass === '99') {
            throw new WorkerTaskProcessingException(
                'El pedido pertenece a un cliente con clase 99 y no debe migrarse.',
                ['payload' => $payload, 'customer_class' => $customerClass]
            );
        }

        if (!$printed) {
            throw new WorkerTaskProcessingException(
                'El pedido aun no esta impreso y no debe migrarse.',
                ['payload' => $payload]
            );
        }

        return [
            'client_code' => $clientCode,
            'client_branch' => $clientBranch,
            'printed' => $printed,
            'customer_class' => $customerClass,
            'is_cancelled' => (int) ($order->PE_IndicadorAnulado ?? 0) === 1,
            'is_manual_request' => (int) ($order->PE_IndicadorSolicitadoManual ?? 0) === 1,
            'is_gift' => (int) ($order->PE_IndicadorObsequio ?? 0) === 1,
        ];
    }
}
