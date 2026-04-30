# Cierre legacy: recibos, pedidos y facturas

## Alcance

Este cierre deja tres flujos adicionales sobre el patrón existente `api -> WorkerHub -> epsa_library -> Siesa -> callback`:

- `receipt_cancellation`
- `order_cancellation`
- `invoice_migration`

Además mantiene:

- auditoría en `siesa_web_services`
- notificación al usuario creador cuando aplica
- `timings_ms` por tarea
- bloqueo de reencolado cuando el estado final ya está alineado en Siesa

## Entradas públicas en WorkerHub

Se agregaron estos endpoints:

- `POST /api/receipt-cancellations`
- `POST /api/order-cancellations`
- `POST /api/invoice-migrations`

Cada endpoint crea una tarea monitorizada y despacha por la misma infraestructura de colas/Kafka que ya usan recibos y pedidos.

## Callbacks internos hacia api

Se agregaron estos callbacks internos:

- `POST /api/internal/workerhub/receipts/cancelled`
- `POST /api/internal/workerhub/orders/cancelled`
- `POST /api/internal/workerhub/invoices/migrated`

Todos validan `X-WorkerHub-Notification-Token` y guardan notificación idempotente por `workerhub_task_id`.

## Disparos desde api

### Recibos

- `ReceiptCreated` sigue disparando `receipt_migration`
- `ReceiptCancellationRequested` dispara `receipt_cancellation`

Puntos de disparo:

- `ReceiptService::cancellation(...)`
- `CancellationRequestController`

### Pedidos

- `OrderApproved` sigue disparando `order_migration`
- `OrderCancellationRequested` dispara `order_cancellation`

Punto de disparo:

- `OrderService::cancelOrder(...)`

### Facturas

- `InvoiceCreated` dispara `invoice_migration`

Puntos de disparo:

- `ReceiptService::createInvoice(...)`
- `ReceiptService::createInvoicePaymentRequest(...)`
- `ReceiptService::createInvoiceGift(...)`

## Lógica de WorkerHub

### `ReceiptCancellationService`

- resuelve encabezado prototipo del recibo
- consulta estado actual en Siesa
- si el recibo ya está anulado (`F350_IND_ESTADO = 2`), sincroniza indicadores legacy y completa la tarea
- si el tipo es `RCM`, falla explícitamente para obligar anulación manual
- en el resto de casos genera el encabezado de anulación con `F350_IND_ESTADO = 2` y notas automáticas

### `OrderCancellationService`

- resuelve encabezado y pedido legacy
- consulta estado en Siesa
- si ya está anulado (`f430_ind_estado = 9`), sincroniza indicadores legacy y completa
- si no, retransmite solo encabezado con `f430_ind_estado = 9`

### `InvoiceMigrationService`

- resuelve factura desde origen
- resuelve encabezado, detalle y caja desde vistas prototipo
- si ya existe en Siesa, sincroniza indicadores legacy y no retransmite
- si no existe, construye líneas con adapters de `epsa_library`
- usa línea de cartera cuando la factura no es de contado
- usa líneas de caja cuando la condición de pago es de contado

## Tablas legacy impactadas

### Recibos

- `pos.recibos_encabezado`
- `pos.recibos_historia_migracion`

### Pedidos

- `pos.pedidos_encabezado`
- `pos.pedidos_historia_migracion`
- `ventas.pedidos_cadenas`

### Facturas

- `pos.facturas_encabezado`

## Pruebas agregadas

Solo se agregaron pruebas aisladas sin `RefreshDatabase`, sin `DatabaseTransactions` y sin SQL Server real:

- `Tests\Unit\Services\Integrations\WorkerHub\WorkerHubFlowSourceTest`
- `Tests\Unit\InvoiceMigrationControllerTest`
- ajuste de firmas en:
  - `DispatchWorkerTaskJobOrderNotificationTest`
  - `WorkerTaskReplayEligibilityServiceTest`

## Estado actual

Este cierre deja operativos los contratos, el ruteo, los callbacks, la instrumentación y los disparos inmediatos de:

- anulación de recibos
- anulación de pedidos
- migración de facturas

La reconciliación batch legacy sigue siendo una capa separada. Debe vivir como proceso dedicado reutilizando estos mismos servicios de dominio, no duplicando la lógica.
