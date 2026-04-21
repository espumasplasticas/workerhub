<?php

namespace App\Services\Workers\Orders;

use stdClass;

class OrderHeaderReferenceResolver
{
    /**
     * @return array{reference_document_number: string, reference: string}
     */
    public function resolve(stdClass $header, ?stdClass $orderRecord = null): array
    {
        $referenceDocumentNumber = $this->truncate(
            $this->firstNonEmpty(
                $header->f430_num_docto_referencia ?? null,
                $header->PE_OrdenDeCompra ?? null,
                $orderRecord?->PE_OrdenDeCompra ?? null,
                $header->PE_OrdenDeCargue ?? null,
                $orderRecord?->PE_OrdenDeCargue ?? null,
                $header->f430_referencia ?? null,
                $orderRecord?->f430_referencia ?? null,
                $header->PE_NumeroDocumento ?? null,
                $orderRecord?->PE_NumeroDocumento ?? null,
            ),
            15
        );

        $reference = $this->truncate(
            $this->firstNonEmpty(
                $header->f430_referencia ?? null,
                $header->PE_OrdenDeCargue ?? null,
                $orderRecord?->PE_OrdenDeCargue ?? null,
                $header->PE_OrdenDeCompra ?? null,
                $orderRecord?->PE_OrdenDeCompra ?? null,
                $header->PE_NumeroDocumento ?? null,
                $orderRecord?->PE_NumeroDocumento ?? null,
            ),
            10
        );

        return [
            'reference_document_number' => $referenceDocumentNumber,
            'reference' => $reference,
        ];
    }

    private function firstNonEmpty(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }
}
