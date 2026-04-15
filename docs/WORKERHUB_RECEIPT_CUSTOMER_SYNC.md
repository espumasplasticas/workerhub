# WorkerHub: Sync previo de tercero para recibos

## Objetivo

Antes de importar un `receipt_migration`, `WorkerHub` replica la validacion historica de `intranetlocal`:

1. valida que el recibo ya pueda migrarse,
2. sincroniza tercero/cliente/sucursal en Siesa cuando aplica,
3. solo despues importa el recibo.

Con esto se evita el rechazo clasico de Siesa por:

- tercero inexistente,
- sucursal inexistente,
- criterios de cliente faltantes.

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

### Flujo

`ReceiptMigrationService` ahora ejecuta:

1. `ReceiptPreMigrationGuard`
2. `ReceiptPrototypeRepository->findHeader()`
3. `EpsaSoapConfigurationValidator`
4. `ReceiptCustomerSyncService->sync(...)`
5. carga medios de pago
6. importa el recibo

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

## Configuracion

Se agrego el bloque:

- `workerhub.receipts.customer_sync`

Variables mas importantes:

- `WORKERHUB_RECEIPT_CUSTOMER_SYNC_ENABLED`
- `WORKERHUB_RECEIPT_CUSTOMER_SYNC_SKIP_COS`
- tablas fuente de clientes/clases/prototipos
- tablas enterprise para terceros/clientes/criterios

## Pruebas

Se dejaron pruebas unitarias sin base de datos:

- `ReceiptCustomerSyncLineFactoryTest`
- `ReceiptCustomerSyncServiceTest`
- ajuste de `ReceiptMigrationServiceTest`

Las pruebas mockean datasource e import manager; no abren conexiones SQL.
