# WorkerHub Operations

## Objetivo

Este modulo deja a `WorkerHub` no solo como API de ingreso de tareas, sino como consola operativa ligera para:

- monitorear tareas en vivo,
- revisar dead letters,
- exportar dead letters en JSON,
- exportar tareas filtradas y auditoria operativa en JSON,
- reencolar tareas terminales,
- reencolar tareas en lote,
- reencolar tareas terminales por filtros,
- seguir el historial de replays,
- auditar acciones operativas sobre el monitor.

## Pantalla web

Ruta:

```http
GET /
GET /monitor
```

La vista muestra:

- resumen de volumen y estado,
- filtros por estado, tipo y origen,
- filtros por prioridad, queue, fechas, replay/original y presencia de error,
- listado de tareas,
- detalle con eventos,
- lineage original -> replay -> replay hijo,
- historial de acciones operativas recientes,
- export de DLQ en JSON,
- export de tareas filtradas y acciones filtradas,
- accion de replay para tareas `failed` o `rejected`.

## Endpoints operativos

### Listado general

```http
GET /api/monitor/tasks
```

Filtros:

- `status`
- `type`
- `source`

### Resumen

```http
GET /api/monitor/tasks/summary
```

Incluye:

- `total`
- `processing`
- `completed`
- `dead_letters`
- `replayed`

### DLQ logica

```http
GET /api/monitor/dead-letters
```

En esta etapa la DLQ es logica, no un topic separado. Se consideran dead letters las tareas con estado:

- `failed`
- `rejected`

### Export de DLQ

```http
GET /api/monitor/dead-letters/export
```

Respuesta:

- `exported_at`
- `count`
- `items`

Cada item devuelve el estado persistido de la tarea, incluyendo:

- `id`
- `type`
- `source`
- `status`
- `priority`
- `queue`
- `attempts`
- `error_message`
- `payload`
- `metadata`

### Replay manual

```http
POST /api/monitor/tasks/{taskId}/retry
```

Comportamiento:

1. valida que la tarea origen este en estado terminal,
2. crea una nueva tarea hija,
3. publica la nueva tarea en Kafka,
4. registra el evento `task.replayed` en la tarea origen.

### Replay por lote

```http
POST /api/monitor/tasks/retry-batch
```

Payload:

```json
{
  "task_ids": ["id-1", "id-2", "id-3"]
}
```

Respuesta:

- `accepted_count`
- `error_count`
- `accepted`
- `errors`

### Historial de acciones

```http
GET /api/monitor/actions
```

Devuelve acciones operativas recientes sobre el monitor, incluyendo:

- vistas del panel,
- consultas de resumen,
- consultas de tareas,
- exportaciones DLQ,
- replay individual,
- replay por lote.

### Export de tareas filtradas

```http
GET /api/monitor/tasks/export
```

Usa los mismos filtros de `GET /api/monitor/tasks`.

### Export de acciones filtradas

```http
GET /api/monitor/actions/export
```

Filtros soportados:

- `worker_task_id`
- `action`
- `status`
- `actor`
- `channel`
- `date_from`
- `date_to`

### Retry por filtros

```http
POST /api/monitor/tasks/retry-filtered
```

Usa los mismos filtros de tarea y solo reencola registros terminales (`failed`, `rejected`).

### Lineage de tarea

```http
GET /api/monitor/tasks/{taskId}/lineage
```

Devuelve:

- `requested_task_id`
- `root_task_id`
- `lineage`

## Modelo de datos

Campos nuevos relevantes en `worker_tasks`:

- `parent_task_id`: referencia logica a la tarea original.
- `replayed_at`: fecha del ultimo replay manual sobre la tarea origen.

Nueva tabla:

- `worker_operation_logs`: auditoria de acciones web y API.

Campos relevantes:

- `action`
- `status`
- `actor`
- `channel`
- `worker_task_id`
- `context`

Filtros operativos de tareas soportados:

- `status`
- `type`
- `source`
- `priority`
- `queue`
- `date_from`
- `date_to`
- `replay_mode`
- `error_mode`
- `only_dead_letters`

## Regla operativa

Una tarea replay no reemplaza la original. Se crea una nueva tarea para preservar:

- trazabilidad,
- intentos historicos,
- auditoria operativa.

## Limitacion actual

La DLQ ya publica en el topic Kafka dedicado configurado en `KAFKA_TOPIC_DEAD_LETTERS`, pero la operacion diaria sigue usando como fuente principal la bandeja persistida en base de datos.

## Seguridad operativa

El panel y los endpoints de monitor usan middleware `workerhub.operator`.

Formas de acceso:

- usuario autenticado cuyo email exista en `WORKERHUB_OPERATIONS_ALLOWED_EMAILS`
- token compartido en header `X-WorkerHub-Token`
- token compartido en query string `?token=...` para el panel web

Si no configuras token ni usuarios permitidos, solo el ambiente `local` queda abierto por conveniencia de desarrollo.

## Auditoria operativa

Cada accion relevante del monitor registra una fila en `worker_operation_logs`.

Acciones auditadas en esta etapa:

- `monitor.view`
- `monitor.tasks.index`
- `monitor.tasks.show`
- `monitor.tasks.summary`
- `monitor.dead_letters.index`
- `monitor.actions.index`
- `dead_letters.export`
- `monitor.tasks.export`
- `monitor.actions.export`
- `monitor.tasks.lineage`
- `task.retry`
- `task.retry_batch`
- `task.retry_filtered`
