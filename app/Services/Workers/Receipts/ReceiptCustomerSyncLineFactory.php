<?php

namespace App\Services\Workers\Receipts;

use App\Data\Receipts\ReceiptCustomerSyncSnapshot;
use App\Exceptions\WorkerTaskProcessingException;
use Epsalibrary\Siesa\Connectors\PrototiposTerceros;
use Epsalibrary\Siesa\Connectors\PrototiposTercerosSucursal;

class ReceiptCustomerSyncLineFactory
{
    /**
     * @return list<string>
     */
    public function build(ReceiptCustomerSyncSnapshot $snapshot): array
    {
        if (!$snapshot->shouldSync) {
            return [];
        }

        if ($snapshot->thirdPartyPrototype === null || $snapshot->branchPrototype === null) {
            throw new WorkerTaskProcessingException(
                'No se pudieron construir las lineas del tercero previo al recibo.',
                ['customer_sync' => $snapshot->toArray()]
            );
        }

        /** @var PrototiposTerceros $thirdParty */
        $thirdParty = $this->hydrate(new PrototiposTerceros(), $snapshot->thirdPartyPrototype);
        /** @var PrototiposTercerosSucursal $branch */
        $branch = $this->hydrate(new PrototiposTercerosSucursal(), $snapshot->branchPrototype);

        return [
            $thirdParty->obtenerLinea(),
            $branch->obtenerLinea(),
            $branch->obtenerLineaImpuestoIva(),
            $branch->obtenerLineaImpuestoIca(),
            $branch->obtenerLineaImpuestoINC(),
            $branch->obtenerLineaRetencionRenta(),
            $branch->obtenerLineaRetencionIva(),
            $branch->obtenerLineaRetencionCree(),
            $branch->obtenerLineaRetencionAURTERTA(),
            $branch->obtenerLineaRetencionIca(),
            $branch->obtenerLineaCriterioSEC(),
            $branch->obtenerLineaCriterioSED(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function hydrate(object $connector, array $attributes): object
    {
        foreach ($attributes as $field => $value) {
            if (is_string($field) && property_exists($connector, $field)) {
                $connector->{$field} = $value;
            }
        }

        return $connector;
    }
}
