<?php

namespace App\Services\Workers;

use App\Models\WorkerTask;
use App\Services\Workers\Invoices\InvoicePrototypeRepository;
use App\Services\Workers\Invoices\InvoiceSiesaStateService;
use App\Services\Workers\Orders\OrderDeliveryGenerationRepository;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use Throwable;

class WorkerTaskReplayEligibilityService
{
    public function __construct(
        private readonly InvoicePrototypeRepository $invoicePrototypeRepository,
        private readonly InvoiceSiesaStateService $invoiceSiesaStateService,
        private readonly OrderDeliveryGenerationRepository $orderDeliveryGenerationRepository,
        private readonly OrderPrototypeRepository $orderPrototypeRepository,
        private readonly OrderSiesaStateService $orderSiesaStateService,
        private readonly ReceiptPrototypeRepository $receiptPrototypeRepository,
        private readonly ReceiptSiesaStateService $receiptSiesaStateService
    ) {
    }

    /**
     * @return array{can_retry: bool, reason: ?string, siesa_state: array<string, mixed>|null}
     */
    public function inspect(WorkerTask $task): array
    {
        if (!in_array($task->status, ['failed', 'rejected'], true)) {
            return [
                'can_retry' => false,
                'reason' => 'Solo se pueden reencolar tareas fallidas o rechazadas.',
                'siesa_state' => null,
            ];
        }

        if ($task->type === 'invoice_migration') {
            $payload = is_array($task->payload) ? $task->payload : [];

            try {
                $header = $this->invoicePrototypeRepository->findHeader($payload);
                $siesaState = $this->invoiceSiesaStateService->fetch($payload, $header);

                if ($siesaState->exists) {
                    return [
                        'can_retry' => false,
                        'reason' => 'La factura ya existe en Siesa y no debe reencolarse.',
                        'siesa_state' => $siesaState->toArray(),
                    ];
                }

                return [
                    'can_retry' => true,
                    'reason' => null,
                    'siesa_state' => $siesaState->toArray(),
                ];
            } catch (Throwable $exception) {
                return [
                    'can_retry' => false,
                    'reason' => 'No se pudo validar si la factura ya existe en Siesa: ' . $exception->getMessage(),
                    'siesa_state' => null,
                ];
            }
        }

        if ($task->type === 'order_delivery_generation') {
            $payload = is_array($task->payload) ? $task->payload : [];

            try {
                $orderRecord = $this->orderPrototypeRepository->findOrderRecord($payload);
                $header = $this->orderPrototypeRepository->findHeader($payload);
                $siesaState = $this->orderSiesaStateService->fetch($payload, $header);

                if (!$this->orderDeliveryGenerationRepository->shouldGenerateDomicile($orderRecord)) {
                    return [
                        'can_retry' => false,
                        'reason' => 'El pedido ya no cumple las condiciones legacy para generar domicilio.',
                        'siesa_state' => $siesaState->toArray(),
                    ];
                }

                $existingDomicile = $this->orderDeliveryGenerationRepository->findActiveDomicileForEnterpriseOrder($payload, $siesaState);

                if ($existingDomicile !== null) {
                    return [
                        'can_retry' => false,
                        'reason' => 'El pedido ya tiene un domicilio activo y no debe reencolarse.',
                        'siesa_state' => $siesaState->toArray(),
                    ];
                }

                return [
                    'can_retry' => true,
                    'reason' => null,
                    'siesa_state' => $siesaState->toArray(),
                ];
            } catch (Throwable $exception) {
                return [
                    'can_retry' => false,
                    'reason' => 'No se pudo validar si el pedido ya tiene domicilio: ' . $exception->getMessage(),
                    'siesa_state' => null,
                ];
            }
        }

        if (in_array($task->type, ['order_migration', 'order_cancellation'], true)) {
            $payload = is_array($task->payload) ? $task->payload : [];

            try {
                $header = $this->orderPrototypeRepository->findHeader($payload);
                $siesaState = $this->orderSiesaStateService->fetch($payload, $header);

                $cancellationRequested = $task->type === 'order_cancellation';

                if ($cancellationRequested && $siesaState->exists && (int) ($siesaState->stateIndicator ?? 0) === 4) {
                    return [
                        'can_retry' => false,
                        'reason' => 'El pedido ya esta cumplido en Siesa y no debe reencolarse.',
                        'siesa_state' => $siesaState->toArray(),
                    ];
                }

                if ($siesaState->exists && (!$cancellationRequested || (int) ($siesaState->stateIndicator ?? 0) === 9)) {
                    return [
                        'can_retry' => false,
                        'reason' => $cancellationRequested
                            ? 'El pedido ya esta anulado en Siesa y no debe reencolarse.'
                            : 'El pedido ya existe en Siesa y no debe reencolarse.',
                        'siesa_state' => $siesaState->toArray(),
                    ];
                }

                return [
                    'can_retry' => true,
                    'reason' => null,
                    'siesa_state' => $siesaState->toArray(),
                ];
            } catch (Throwable $exception) {
                return [
                    'can_retry' => false,
                    'reason' => 'No se pudo validar si el pedido ya existe en Siesa: ' . $exception->getMessage(),
                    'siesa_state' => null,
                ];
            }
        }

        if (!in_array($task->type, ['receipt_migration', 'receipt_cancellation'], true)) {
            return [
                'can_retry' => true,
                'reason' => null,
                'siesa_state' => null,
            ];
        }

        $payload = is_array($task->payload) ? $task->payload : [];

        try {
            $header = $this->receiptPrototypeRepository->findHeader($payload);
            $siesaState = $this->receiptSiesaStateService->fetch($payload, $header);

            $cancellationRequested = $task->type === 'receipt_cancellation';

            if (!$cancellationRequested && $siesaState->exists) {
                return [
                    'can_retry' => true,
                    'reason' => null,
                    'siesa_state' => $siesaState->toArray(),
                ];
            }

            if ($cancellationRequested && $siesaState->exists && (int) ($siesaState->stateIndicator ?? 0) === 2) {
                return [
                    'can_retry' => true,
                    'reason' => null,
                    'siesa_state' => $siesaState->toArray(),
                ];
            }

            return [
                'can_retry' => true,
                'reason' => null,
                'siesa_state' => $siesaState->toArray(),
            ];
        } catch (Throwable $exception) {
            return [
                'can_retry' => false,
                'reason' => 'No se pudo validar si el recibo ya existe en Siesa: ' . $exception->getMessage(),
                'siesa_state' => null,
            ];
        }
    }
}
