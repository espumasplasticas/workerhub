<?php

namespace App\Services\Workers;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Orders\OrderDeliveryGenerationRepository;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use Illuminate\Contracts\Config\Repository;
use stdClass;

class OrderDeliveryGenerationService
{
    public function __construct(
        private readonly Repository $config,
        private readonly OrderPrototypeRepository $orderPrototypeRepository,
        private readonly OrderSiesaStateService $orderSiesaStateService,
        private readonly OrderLegacyStateService $orderLegacyStateService,
        private readonly OrderDeliveryGenerationRepository $orderDeliveryGenerationRepository
    ) {
    }

    /**
     * Genera o vincula el domicilio del pedido ya migrado en Siesa siguiendo la regla del legacy.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $documentId = $payload['document_id'] ?? $this->buildReference($payload);

        if (!$this->isEnabled()) {
            return [
                'document_id' => $documentId,
                'message' => 'La generacion de domicilios posteriores a la migracion esta deshabilitada.',
                'status' => 'skipped',
                'domicile' => null,
            ];
        }

        $orderRecord = $this->orderPrototypeRepository->findOrderRecord($payload);
        $header = $this->orderPrototypeRepository->findHeader($payload);
        $siesaState = $this->orderSiesaStateService->fetch($payload, $header);

        if (!$siesaState->exists) {
            return [
                'document_id' => $documentId,
                'message' => 'El pedido no existe en Siesa y no se puede generar el domicilio.',
                'status' => 'skipped',
                'siesa_state' => $siesaState->toArray(),
                'domicile' => null,
            ];
        }

        if ((int) ($siesaState->stateIndicator ?? 0) === 0) {
            throw new WorkerTaskProcessingException(
                'El pedido aun esta en estado 0 en Siesa. Se reintentara la generacion del domicilio.',
                [
                    'document_id' => $documentId,
                    'siesa_state' => $siesaState->toArray(),
                ]
            );
        }

        if (in_array((int) ($siesaState->stateIndicator ?? 0), [4, 9], true)) {
            return [
                'document_id' => $documentId,
                'message' => 'El pedido no esta en un estado valido de Siesa para generar domicilio.',
                'status' => 'skipped',
                'siesa_state' => $siesaState->toArray(),
                'domicile' => null,
            ];
        }

        if (!$this->orderDeliveryGenerationRepository->shouldGenerateDomicile($orderRecord)) {
            return [
                'document_id' => $documentId,
                'message' => 'El pedido no cumple las condiciones legacy para generar domicilio.',
                'status' => 'skipped',
                'siesa_state' => $siesaState->toArray(),
                'domicile' => null,
            ];
        }

        $existingDomicile = $this->orderDeliveryGenerationRepository->findActiveDomicileForEnterpriseOrder(
            $payload,
            $siesaState
        );

        if ($existingDomicile instanceof stdClass) {
            $this->orderLegacyStateService->updateLinkedDomicileReference(
                $payload,
                trim((string) $existingDomicile->DP_TipoId),
                (int) $existingDomicile->DP_Id
            );

            return [
                'document_id' => $documentId,
                'message' => 'El pedido ya tenia un domicilio activo y se sincronizo la referencia legacy.',
                'status' => 'already_exists',
                'siesa_state' => $siesaState->toArray(),
                'domicile' => [
                    'type' => trim((string) $existingDomicile->DP_TipoId),
                    'number' => (int) $existingDomicile->DP_Id,
                    'mode' => 'existing',
                ],
            ];
        }

        $enterpriseOrder = $this->orderDeliveryGenerationRepository->findEnterpriseOrderContext($payload, $siesaState);
        $detailLines = $this->orderDeliveryGenerationRepository->findEnterpriseOrderDetailComponents($payload, $siesaState);
        $parentDomicile = $this->orderDeliveryGenerationRepository->findReusableGiftParentDomicile($payload, $orderRecord);

        if ($parentDomicile instanceof stdClass) {
            $attachedDomicile = $this->orderDeliveryGenerationRepository->attachEnterpriseOrderToExistingDomicile(
                $payload,
                $siesaState,
                $detailLines,
                trim((string) $parentDomicile->PE_DomicilioTipo_Padre),
                (int) $parentDomicile->PE_DomicilioNumero_Padre
            );

            $this->orderLegacyStateService->updateLinkedDomicileReference(
                $payload,
                $attachedDomicile['type'],
                $attachedDomicile['number']
            );

            return [
                'document_id' => $documentId,
                'message' => 'El pedido obsequio quedo adicionado al domicilio del pedido padre.',
                'status' => 'attached',
                'siesa_state' => $siesaState->toArray(),
                'domicile' => $attachedDomicile,
            ];
        }

        $requestedDomicileType = $this->orderDeliveryGenerationRepository->resolveRequestedDomicileType(
            $payload,
            $orderRecord,
            $siesaState
        );
        $createdDomicile = $this->orderDeliveryGenerationRepository->createDomicileForEnterpriseOrder(
            $payload,
            $orderRecord,
            $enterpriseOrder,
            $detailLines,
            $requestedDomicileType
        );

        $this->orderLegacyStateService->updateLinkedDomicileReference(
            $payload,
            $createdDomicile['type'],
            $createdDomicile['number']
        );

        return [
            'document_id' => $documentId,
            'message' => 'Domicilio generado correctamente para el pedido migrado.',
            'status' => 'created',
            'siesa_state' => $siesaState->toArray(),
            'domicile' => $createdDomicile,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildReference(array $payload): string
    {
        return implode('-', array_filter([
            trim((string) ($payload['operational_center'] ?? '')),
            trim((string) ($payload['document_type'] ?? '')),
            trim((string) ($payload['document_number'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('workerhub.orders.delivery_generation.enabled', true);
    }
}
