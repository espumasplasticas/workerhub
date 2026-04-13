# WorkerHub Operations

## Objetivo

Este modulo deja a `WorkerHub` no solo como API de ingreso de tareas, sino como consola operativa ligera para:

- monitorear tareas en vivo,
- revisar dead letters,
- reencolar tareas terminales,
- reencolar tareas en lote,
- seguir el historial de replays.

## Pantalla web

Ruta:

```http
GET /
GET /monitor
```

La vista muestra:

- resumen de volumen y estado,
- filtros por estado, tipo y origen,
- listado de tareas,
- detalle con eventos,
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

## Modelo de datos

Campos nuevos relevantes en `worker_tasks`:

- `parent_task_id`: referencia logica a la tarea original.
- `replayed_at`: fecha del ultimo replay manual sobre la tarea origen.

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
