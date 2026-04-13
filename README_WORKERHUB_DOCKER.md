# WorkerHub Docker y Workers

## Que deja implementado

- `nginx` con balanceo hacia `php-1` y `php-2`.
- `Horizon` como supervisor de workers Redis con auto-balanceo por demanda.
- `Redpanda` como broker compatible con Kafka.
- `redpanda-console` para inspeccionar topics y mensajes.
- `kafka-consumer` para consumir solicitudes y encolarlas en Redis.
- `scheduler` para snapshots de Horizon.
- `echo-server` para notificaciones en tiempo real via Socket.IO usando Redis como backend de broadcasting.
- panel web operativo en `/monitor` con replay manual y vista de DLQ.
- proteccion del panel y endpoints operativos mediante token o usuarios permitidos.

## Flujo operativo

1. Una aplicacion llama primero a Laravel `WorkerHub`.
2. `WorkerHub` registra la tarea, la publica en Kafka y deja trazabilidad en SQL Server.
3. `kafka-consumer` consume el mensaje y lo convierte en un job Redis.
4. `Horizon` ajusta la cantidad de procesos por cola segun la carga.
5. El job procesa la tarea.
6. WorkerHub publica resultado o fallo en Kafka y emite eventos por sockets para el monitor.

## Servicios

- API Laravel: `http://localhost:8080`
- Horizon: `http://localhost:8080/horizon`
- Redpanda Console: `http://localhost:8081`
- Echo Server / Socket.IO: `http://localhost:6001`
- Kafka externo: `localhost:19092`
- Redis externo: `localhost:6381`
- SQL Server: externo al compose, configurado por `DB_HOST`/`DB_PORT`

## Primer arranque

```bash
cp .env.docker .env
docker compose build
docker compose run --rm php-1 composer install
docker compose run --rm php-1 php artisan key:generate
docker compose run --rm php-1 php artisan workerhub:bootstrap-sqlsrv-dev
docker compose up -d
```

Si ya tienes `.env` local y no quieres reemplazarlo, usa `.env.docker` solo como referencia.

## Endpoints

### Health

```http
GET /api/health/workerhub
```

### Crear tarea generica

```http
POST /api/worker-tasks
Content-Type: application/json

{
  "type": "document_migration",
  "priority": "default",
  "payload": {
    "document_id": "DOC-1001",
    "source": "crm",
    "lines": [
      "04300002001...",
      "04310002001..."
    ]
  }
}
```

### Crear solicitud especifica de migracion

```http
POST /api/document-migrations
Content-Type: application/json

{
  "document_id": "DOC-1001",
  "source": "crm",
  "priority": "high",
  "lines": [
    "04300002001...",
    "04310002001..."
  ]
}
```

### Monitor de tareas

```http
GET /api/monitor/tasks
GET /api/monitor/tasks/summary
GET /api/monitor/dead-letters
GET /api/monitor/socket-config
POST /api/monitor/tasks/retry-batch
POST /api/monitor/tasks/{task_id}/retry
GET /api/monitor/tasks/{task_id}
```

Estados actuales:

- `received`
- `published`
- `queued`
- `processing`
- `completed`
- `failed`
- `rejected`

Cada tarea queda registrada en base de datos con historial de eventos para que Laravel sea el punto central de monitoreo.

## Consola operativa web

- URL: `http://localhost:8080/monitor`
- Funciones:
  - resumen de estados,
  - filtros por tipo/estado/origen,
  - detalle por tarea,
  - replay manual de tareas `failed` y `rejected`,
  - replay por lote,
  - vista operativa de DLQ logica.

## Sockets y monitoreo en tiempo real

- Broadcaster: `pusher`
- Host interno Docker: `echo-server:6001`
- Host cliente local: `localhost:6001`
- Canal general: `workerhub.monitor`
- Canal por tarea: `workerhub.tasks.{task_id}`

El endpoint `GET /api/monitor/socket-config` expone la configuracion de conexion para dashboards o consumidores externos.

## Autoescalado

El autoescalado actual es a nivel de procesos dentro de `Horizon`, no a nivel de contenedores.

- Cola `migration-high` y `migration-default`:
  - supervisor `supervisor-migrations`
- Cola `integration` y `default`:
  - supervisor `supervisor-integrations`

Si necesitas escalar contenedores automaticamente, el siguiente paso serio es `Kubernetes + KEDA`. Para Docker Compose el control dinamico realista y estable es Horizon.

## Variables clave

- `QUEUE_CONNECTION=redis`
- `CACHE_DRIVER=redis`
- `REDIS_CLIENT=predis`
- `DB_CONNECTION=sqlsrv`
- `DB_HOST` y `DB_PORT` apuntando al SQL Server externo
- `KAFKA_BROKERS=redpanda:9092`
- `KAFKA_TOPIC_REQUESTS=workerhub.tasks.requests`
- `KAFKA_TOPIC_RESULTS=workerhub.tasks.results`
- `KAFKA_TOPIC_FAILURES=workerhub.tasks.failures`
- `KAFKA_TOPIC_DEAD_LETTERS=workerhub.tasks.dead_letters`
- `BROADCAST_DRIVER=pusher`
- `PUSHER_HOST=echo-server`
- `PUSHER_PORT=6001`
- `WORKERHUB_OPERATIONS_TOKEN`
- `WORKERHUB_OPERATIONS_ALLOWED_EMAILS`
- `EPSA_SIESA_*` para credenciales y configuracion de importacion a Siesa

## Nota sobre epsa_library

`WorkerHub` ya esta preparado para resolver `Epsalibrary\Contracts\ImportManagerInterface` desde Laravel y usarlo dentro del job `document_migration`. Solo falta completar las variables `EPSA_SIESA_*` reales del ambiente donde importaras.

## Migraciones nuevas

Este flujo agrega tablas para monitoreo:

- `worker_tasks`
- `worker_task_events`

## Documentacion tecnica

- Documento funcional/tecnico: `docs/WORKERHUB_IMPLEMENTACION.md`
- Arquitectura resumida: `docs/ARQUITECTURA_WORKERHUB.md`
- Desarrollo SQL Server: `docs/WORKERHUB_SQLSERVER_DEV.md`
- Sockets y monitoreo real time: `docs/WORKERHUB_SOCKETS.md`
- Operacion y replay manual: `docs/WORKERHUB_OPERATIONS.md`
- API docs con Doctum:

```bash
php vendor/bin/doctum.php update doctum.php
```
