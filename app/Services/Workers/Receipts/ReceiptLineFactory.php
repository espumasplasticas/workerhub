<?php

namespace App\Services\Workers\Receipts;

use Epsalibrary\Legacy\Adapters\Receipts\LegacyReceiptHeaderAdapter;
use Epsalibrary\Legacy\Adapters\Receipts\LegacyReceiptPaymentAdapter;
use Epsalibrary\Siesa\Connectors\PrototipoReciboCaja;
use Epsalibrary\Siesa\Connectors\PrototipoReciboEncabezado;
use stdClass;

class ReceiptLineFactory
{
    /**
     * @param list<stdClass> $paymentRows
     * @return list<string>
     */
    public function build(stdClass $headerRow, array $paymentRows): array
    {
        $headerConnector = $this->toHeaderConnector($headerRow);

        $lines = [
            (new LegacyReceiptHeaderAdapter($headerConnector))->toLine(),
        ];

        foreach ($paymentRows as $paymentRow) {
            $lines[] = (new LegacyReceiptPaymentAdapter(
                $this->toPaymentConnector($paymentRow)
            ))->toLine();
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function buildCancellation(stdClass $headerRow): array
    {
        $cancellationHeader = clone $headerRow;
        $existingNotes = trim((string) ($cancellationHeader->F350_NOTAS ?? ''));
        $automaticNotes = sprintf(
            '***ANULADO*** POR PROCESO AUTOMATICO FECHA: %s',
            now()->format('Y-m-d H:i:s')
        );

        $cancellationHeader->F350_IND_ESTADO = 2;
        $cancellationHeader->F350_NOTAS = trim($existingNotes . ' ' . $automaticNotes);

        return [
            (new LegacyReceiptHeaderAdapter($this->toHeaderConnector($cancellationHeader)))->toLine(),
        ];
    }

    private function toHeaderConnector(stdClass $row): PrototipoReciboEncabezado
    {
        $connector = new PrototipoReciboEncabezado();

        foreach ([
            'F350_ID_CO',
            'F350_ID_TIPO_DOCTO',
            'F350_CONSEC_DOCTO',
            'F350_FECHA',
            'F357_ID_CAJA',
            'F357_FECHA_RECAUDO',
            'F350_ID_TERCERO',
            'F357_ID_MONEDA_INGRESO',
            'F357_VALOR_INGRESO',
            'F357_ID_MONEDA_APLICAR',
            'F357_VALOR_APLICAR_REAL',
            'F357_ID_COBRADOR',
            'F357_ID_UN',
            'F357_ID_CCOSTO',
            'F357_ID_FE',
            'F350_ID_CLASE_DOCTO',
            'F350_IND_ESTADO',
            'F350_IND_IMPRESION',
            'F350_NOTAS',
            'F351_ID_AUXILIAR_AJUSTE',
            'F351_ID_CCOSTO_AJUSTE',
            'F351_ID_AUXILIAR_PP',
            'F351_ID_CCOSTO_PP',
            'F351_ID_AUXILIAR_OTRO_ING',
            'F351_ID_TERCERO_OTRO_ING',
            'F351_ID_SUCURSAL_OTRO_ING',
            'F351_ID_CO_OTRO_ING',
            'F351_ID_UN_OTRO_ING',
            'F351_ID_CCOSTO_OTRO_ING',
            'F357_REFERENCIA',
            'F353_ID_SUCURSAL_DOCTO_CRUCE',
        ] as $field) {
            $connector->{$field} = $row->{$field} ?? null;
        }

        return $connector;
    }

    private function toPaymentConnector(stdClass $row): PrototipoReciboCaja
    {
        $connector = new PrototipoReciboCaja();

        foreach ([
            'F350_ID_CO',
            'F350_ID_TIPO_DOCTO',
            'F350_CONSEC_DOCTO',
            'F358_ID_MEDIOS_PAGO',
            'F358_VALOR',
            'F358_ID_BANCO',
            'F358_NRO_CHEQUE',
            'F358_NRO_CUENTA',
            'F358_COD_SEGURIDAD',
            'F358_NRO_AUTORIZACION',
            'F358_FECHA_VCTO',
            'F358_REFERENCIA_OTROS',
            'F358_FECHA_CONSIGNACION',
            'F358_ID_CAUSALES_DEVOLUCION',
            'F358_ID_TERCERO',
            'F358_NOTAS',
            'F358_ID_CCOSTO',
        ] as $field) {
            $connector->{$field} = $row->{$field} ?? null;
        }

        return $connector;
    }
}
