<?php

namespace Tests\Unit;

use App\Data\Orders\OrderSiesaStateSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\OrderDeliveryGenerationService;
use App\Services\Workers\Orders\OrderDeliveryGenerationRepository;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\TestCase;
use stdClass;

class OrderDeliveryGenerationServiceTest extends TestCase
{
    public function test_it_retries_when_the_enterprise_order_is_still_in_state_zero(): void
    {
        $config = $this->createMock(Repository::class);
        $prototypeRepository = $this->createMock(OrderPrototypeRepository::class);
        $siesaStateService = $this->createMock(OrderSiesaStateService::class);
        $legacyStateService = $this->createMock(OrderLegacyStateService::class);
        $deliveryRepository = $this->createMock(OrderDeliveryGenerationRepository::class);

        $config->expects($this->once())
            ->method('get')
            ->with('workerhub.orders.delivery_generation.enabled', true)
            ->willReturn(true);

        $prototypeRepository->expects($this->once())
            ->method('findOrderRecord')
            ->willReturn((object) []);

        $prototypeRepository->expects($this->once())
            ->method('findHeader')
            ->willReturn((object) []);

        $siesaStateService->expects($this->once())
            ->method('fetch')
            ->willReturn(new OrderSiesaStateSnapshot(
                operationalCenter: '002',
                documentType: 'FC',
                documentNumber: '22865',
                exists: true,
                enterpriseOperationalCenter: '002',
                enterpriseDocumentType: 'PFC',
                enterpriseDocumentNumber: '22865',
                rowId: 1136439,
                netTotal: 1000.0,
                stateIndicator: 0,
            ));

        $deliveryRepository->expects($this->never())
            ->method('shouldGenerateDomicile');

        $service = new OrderDeliveryGenerationService(
            $config,
            $prototypeRepository,
            $siesaStateService,
            $legacyStateService,
            $deliveryRepository
        );

        $this->expectException(WorkerTaskProcessingException::class);
        $this->expectExceptionMessage('El pedido aun esta en estado 0 en Siesa. Se reintentara la generacion del domicilio.');

        $service->handle([
            'db_connection' => 'test',
            'document_id' => '002-FC-22865',
            'operational_center' => '002',
            'document_type' => 'FC',
            'document_number' => '22865',
        ]);
    }
}
