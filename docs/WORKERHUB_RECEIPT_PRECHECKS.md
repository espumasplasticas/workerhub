# Precondiciones de Migracion de Recibos

## Objetivo

Antes de enviar un recibo a `epsa_library` y a Siesa, `WorkerHub` ahora replica la validacion operativa que antes vivia en `intranetlocal/procesos/pedidos/ImportarRecibosCada1.php`.

## Reglas migradas

`WorkerHub` solo deja continuar la migracion si el recibo cumple al menos una de estas condiciones:

- el valor legalizado es mayor o igual al valor total del recibo,
- el recibo ya esta anulado,
- el recibo ya tiene solicitud de anulacion,
- el recibo Wompi quedo vencido sin pago,
- el tipo de documento es `A06`,
- el tipo de documento es `RCP`.

Si ninguna regla se cumple, la tarea `receipt_migration` falla antes de construir lineas o llamar a Siesa.

## Diseno

- `ReceiptPreMigrationDataSourceInterface`
  Resuelve los datos fuente necesarios para evaluar el recibo.
- `SqlReceiptPreMigrationDataSource`
  Lee el recibo y ejecuta las funciones SQL de legalizacion y vencimiento Wompi.
- `ReceiptPreMigrationGuard`
  Encapsula la politica de negocio y decide si el recibo puede migrarse.
- `ReceiptMigrationService`
  Solo coordina el flujo: valida precondiciones, arma lineas y ejecuta la importacion.

## Configuracion

Variables opcionales:

- `WORKERHUB_RECEIPT_PRE_MIGRATION_ENABLED`
- `WORKERHUB_RECEIPT_TABLE`
- `WORKERHUB_RECEIPT_LEGALIZED_AMOUNT_FUNCTION`
- `WORKERHUB_RECEIPT_EXPIRED_WOMPI_FUNCTION`

Defaults:

- tabla: `pos.recibos_encabezado`
- funcion legalizado: `pos.fun_recibos_wompi_valor_legalizado`
- funcion Wompi vencido: `pos.fun_recibos_wompi_es_sin_pago_vencido`

## Pruebas

La politica nueva se cubre con pruebas unitarias puras, sin base de datos:

- `ReceiptPreMigrationGuardTest`
- `ReceiptMigrationServiceTest`

Las pruebas mockean la fuente de datos y validan solo las reglas y el flujo de orquestacion.