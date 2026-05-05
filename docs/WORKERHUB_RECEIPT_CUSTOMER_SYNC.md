# WorkerHub: Sync previo de terceros y guardas de cruce para recibos

## Objetivo

Antes de importar un `receipt_migration`, `WorkerHub` replica la validacion historica de `intranetlocal`:

1. valida que el recibo ya pueda migrarse,
2. sincroniza tercero/cliente/sucursal en Siesa cuando aplica,
3. valida que el documento de cruce exista en cartera abierta,
4. solo despues importa el recibo.

Con esto se evita el rechazo clasico de Siesa por:

- tercero inexistente,
- sucursal inexistente,
- criterios de cliente faltantes.
- documento de cruce inexistente.

## Origen legacy

La logica original estaba distribuida en:

- `intranetlocal/clases/Pedidos/ExportarRecibosWS.class.php`
- `intranetlocal/clases/ExportadorEnterprise.class.php`
- `intranetlocal/db/mssql/pedidos/Service.class.php`

El paso critico era `crearTercero(...)` antes de escribir el encabezado del recibo.

## Implementacion en WorkerHub

### Servicios nuevos

- `ReceiptCustomerSyncDataSourceInterface`
- `SqlReceiptCustomerSyncDataSource`
- `ReceiptCustomerSyncLineFactory`
- `ReceiptCustomerSyncService`
- `ReceiptCustomerSyncSnapshot`
- `ReceiptCrossReferenceDataSourceInterface`
- `SqlReceiptCrossReferenceDataSource`
- `ReceiptCrossReferenceGuard`
- `ReceiptCrossReferenceSnapshot`

### Flujo

`ReceiptMigrationService` ahora ejecuta:

1. `ReceiptPreMigrationGuard`
2. `ReceiptPrototypeRepository->findHeader()`
3. `EpsaSoapConfigurationValidator`
4. `ReceiptCrossReferenceGuard->assertExists(...)`
5. `ReceiptCustomerSyncService->sync(...)`
6. carga medios de pago
7. importa el recibo

## Reglas portadas

### Cuando se omite el sync de tercero

- si el centro operativo enterprise del recibo es `A40` o `A06`
- si la clase de cliente no permite seleccion
- si el tercero/cliente ya existe en Enterprise y no debe reimportarse

### Cuando si se sincroniza

Se importan estas lineas, en este orden:

1. tercero `0200`
2. cliente sucursal `0201`
3. impuesto IVA
4. impuesto ICA
5. impuesto INC
6. retencion renta
7. retencion IVA
8. retencion CREE
9. retencion AURTERTA
10. retencion ICA
11. criterio `SEC`
12. criterio `SED`

### Terceros dependientes del encabezado

Si el recibo referencia un tercero distinto en:

- `F351_ID_TERCERO_OTRO_ING`
- `F351_ID_SUCURSAL_OTRO_ING`

`WorkerHub` intenta sincronizar tambien ese tercero auxiliar antes de enviar el `0357`.

### Guarda de documento de cruce

Antes del import, `WorkerHub` valida en `SiesaEnterprise` que exista una fila abierta en:

- `t353_co_saldo_abierto`
- ligada al auxiliar configurado, por defecto `28050505`

usando centro operativo, unidad, sucursal, tipo y consecutivo de cruce.

## Configuracion

Se agrego el bloque:

- `workerhub.receipts.customer_sync`

Variables mas importantes:

- `WORKERHUB_RECEIPT_CUSTOMER_SYNC_ENABLED`
- `WORKERHUB_RECEIPT_CUSTOMER_SYNC_SKIP_COS`
- tablas fuente de clientes/clases/prototipos
- tablas enterprise para terceros/clientes/criterios
- `WORKERHUB_RECEIPT_CROSS_REFERENCE_GUARD_ENABLED`
- `WORKERHUB_RECEIPT_CROSS_REFERENCE_AUXILIARY_ID`
- `WORKERHUB_RECEIPT_CROSS_REFERENCE_UNIT`
- tablas enterprise de `open_balances` y `auxiliaries`

## Pruebas

Se dejaron pruebas unitarias sin base de datos:

- `ReceiptCustomerSyncLineFactoryTest`
- `ReceiptCustomerSyncServiceTest`
- `ReceiptCrossReferenceGuardTest`
- ajuste de `ReceiptMigrationServiceTest`

Las pruebas mockean datasource e import manager; no abren conexiones SQL.
