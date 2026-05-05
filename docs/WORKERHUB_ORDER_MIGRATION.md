# WorkerHub: migracion de pedidos `order_migration`

## 1. Objetivo

Cuando `api` crea un pedido POS, dispara una tarea `order_migration` hacia WorkerHub.  
WorkerHub replica la logica legacy de intranet para:

- validar si el pedido debe migrarse
- sincronizar tercero y sucursal cuando aplica
- construir el batch Siesa con `epsa_library`
- guardar XML y resultado en `siesa_web_services`
- actualizar indicadores legacy del pedido
- notificar al usuario creador en `api` cuando el pedido ya fue migrado

## 2. Flujo completo

1. `api` crea el pedido.
2. `api` emite `OrderCreated`.
3. `DispatchOrderMigrationToWorkerHub` construye payload y llama `POST /api/order-migrations`.
4. WorkerHub valida si el pedido ya existe en Siesa antes de encolar.
5. Si no existe:
   - crea `worker_task`
   - despacha la tarea por Kafka o cola directa
6. `DispatchWorkerTaskJob` ejecuta `OrderMigrationService`.
7. `OrderMigrationService`:
   - valida el pedido legacy
   - obtiene header y detail prototipo
   - sincroniza tercero/sucursal
   - construye `lines`
   - importa con `SiesaImportAuditService`
8. WorkerHub consulta nuevamente Siesa, sincroniza indicadores legacy y callback a `api`.
9. `api` persiste la notificacion en `notifications` para el `users.id` creador.

## 3. Endpoint publico

### `POST /api/order-migrations`

Payload esperado:

```json
{
  "order_id": 1411395,
  "document_id": "002-FC-24116",
  "db_connection": "test",
  "operational_center": "002",
  "document_type": "FC",
  "document_number": "24116",
  "company_id": 2,
  "client_code": "900123",
  "client_branch": "001",
  "order_total": 120000,
  "source": "api",
  "priority": "default",
  "process_key": "sales_orders",
  "process_label": "Pedidos",
  "schedule_name": "API_ORDER_CREATED",
  "task_name": "Migracion pedido POS",
  "metadata": {
    "created_by_user_id": 184
  }
}
```

Respuesta:

```json
{
  "accepted": true,
  "task_id": "uuid",
  "document_id": "002-FC-24116",
  "topic": "workerhub.tasks.requests",
  "dispatch_mode": "kafka",
  "queue": null
}
```

Si el pedido ya existe en Siesa:

```json
{
  "accepted": false,
  "message": "El pedido ya existe en Siesa y no debe encolarse nuevamente.",
  "document_id": "002-FC-24116",
  "siesa_state": {
    "exists": true
  }
}
```

## 4. Donde se construye `lines`

La orquestacion ocurre en:

- `app/Services/Workers/OrderMigrationService.php`

La construccion real queda separada en dos bloques:

### 4.1 Tercero y sucursal

- `app/Services/Workers/Orders/OrderCustomerSyncService.php`

Reutiliza la infraestructura de recibos para:

- validar migrabilidad del cliente
- preparar prototipo de tercero
- preparar prototipo de sucursal
- generar sus lineas antes del pedido

### 4.2 Pedido y detalle

- `app/Services/Workers/Orders/OrderLineFactory.php`

Este servicio:

- muta el encabezado con reglas legacy
- muta cada detalle con reglas de kits, motivos, backorder, bodegas y descuentos
- usa `epsa_library` para convertir encabezado y detalle a lineas legacy

Conectores/adaptadores usados:

- `LegacySalesOrderHeaderAdapter`
- `LegacySalesOrderLineAdapter`
- `PrototipoPedidoEncabezado`
- `PrototipoPedidoDetalle`

## 5. Uso de `epsa_library`

La frontera exacta con `epsa_library` esta en:

- `app/Services/Workers/SiesaImportAuditService.php`

Llamada principal:

```php
$result = $this->importManager->import($lines);
```

Antes de esa llamada WorkerHub:

- ya construyo `lines`
- ya genero el XML con `ImportBatchBuilder`
- ya guardo el registro previo en `siesa_web_services`

Despues de esa llamada WorkerHub:

- evalua si la importacion fue exitosa
- guarda `result` y `result_text`
- actualiza estado legacy del pedido

## 6. Logica legacy migrada

Paridad replicada desde `ImportarPedidosCada1.php` y `ExportarPedidoWS.class.php`:

- valida si el pedido ya existe en Siesa antes de encolar y antes de importar
- valida cliente del pedido
- bloquea clase de cliente `99`
- bloquea pedidos no impresos
- prepara detalle con `pos.usp_pedidos_detalle_items_con_kits`
- valida existencia del item en `t120_mc_items`
- valida consistencia entre `PD_Kit`, `PD_Referencia` y `PD_CodigoItem`
- corrige `f430_id_co` y `f431_id_co` por activacion legacy
- corrige unidad de medida desde `maestros_uno.cmitems_catalogo_de_items`
- aplica reglas de:
  - salas `0240`, `0242`, `0250`
  - notas adicionales
  - pedidos de obsequio
  - pedidos de cadena
  - backorder
  - motivo `VE`, `ME`, `02`
  - items `900001`, `800013`, `110084`
  - lineas de descuento y descuento manual

## 7. Idempotencia y verificacion posterior

Servicios clave:

- `OrderSiesaStateService`
- `OrderLegacyStateService`
- `WorkerTaskReplayEligibilityService`

Comportamiento:

- si el pedido ya existe en Siesa, no se encola
- si una tarea fallida ya corresponde a un pedido existente en Siesa, no se puede reencolar
- despues de importar, WorkerHub consulta otra vez Siesa
- si el pedido existe y la diferencia de valor neto esta bajo el umbral configurado, se marca verificado

Campos legacy sincronizados:

- `PE_IndicadorMigrado`
- `PE_FechaMigracion`
- `PE_CodigoUsuarioMigro`
- `PE_ConsecutivoDeMigracion`
- `PE_EstadoVerificadoExportacion`
- `PE_FechaVerificacionExportacion`
- `PE_IndicadorImpreso`
- `PE_rowid_t430_pedido`

Tablas legacy impactadas:

- `pos.pedidos_encabezado`
- `pos.pedidos_historia_migracion`
- `ventas.pedidos_cadenas`

## 8. Callback a `api`

WorkerHub usa:

- `app/Services/Integrations/Api/OrderMigrationNotificationClient.php`

Endpoint destino:

- `POST /api/internal/workerhub/orders/migrated`

Payload:

```json
{
  "task_id": "uuid",
  "document_id": "002-FC-24116",
  "order_id": 1411395,
  "company_id": 2,
  "client_code": "900123",
  "client_branch": "001",
  "order_total": 120000,
  "created_by_user_id": 184,
  "completed_at": "2026-04-20T12:00:00-05:00",
  "result": {}
}
```

En `api`, `OrderMigrationNotificationService`:

- resuelve el `users.id`
- guarda la notificacion
- publica el mensaje tipo `Pedido migrado 002-FC-24116`

## 9. XML audit

Cada importacion de pedido registra en `siesa_web_services`:

- `worker_task_id`
- `task_type=order_migration`
- `document_id`
- `source`
- `import_stage=order_migration`
- `context`
- `xml`
- `result`
- `result_text`
- `processed_at`

## 10. Verificacion manual

1. Crear un pedido nuevo en `api`.
2. Confirmar que `api` dispare `OrderCreated`.
3. Confirmar que WorkerHub registre la tarea con proceso `Pedidos`.
4. Confirmar que `siesa_web_services` reciba el XML y la respuesta.
5. Confirmar que `pos.pedidos_encabezado` actualice indicadores.
6. Confirmar que la campana del usuario creador muestre `Pedido migrado`.

