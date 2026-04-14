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
- autenticacion web propia validada contra `backoffice_service`.
- fallback tecnico por token solo para soporte/automatizacion controlada.
- healthchecks operativos ejecutables desde contenedor con `php artisan workerhub:healthcheck`.

## Flujo operativo

1. Una aplicacion llama primero a Laravel `WorkerHub`.
2. `WorkerHub` registra la tarea y deja trazabilidad en SQL Server.
3. Si Kafka esta habilitado, publica en Kafka y `kafka-consumer` convierte el mensaje en un job Redis.
4. Si Kafka esta deshabilitado en desarrollo, `WorkerHub` puede usar `direct_queue` hacia Redis.
5. `Horizon` ajusta la cantidad de procesos por cola segun la carga.
6. El job procesa la tarea.
7. WorkerHub publica resultado o fallo en Kafka cuando ese canal esta habilitado y emite eventos por sockets para el monitor.

## Servicios

- API Laravel: `http://localhost:5010`
- Horizon: `http://localhost:5010/horizon`
- Redpanda Console: `http://localhost:8081`
- Echo Server / Socket.IO: `http://localhost:6002`
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

Healthchecks Docker:

- `php-1`, `php-2`, `horizon`, `kafka-consumer` y `scheduler` ejecutan `php artisan workerhub:healthcheck`
- el comando marca degradado cuando fallan SQL Server, Redis, Kafka o la dependencia de autenticacion contra backoffice

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
GET /api/monitor/tasks/export
GET /api/monitor/tasks/summary
GET /api/monitor/dead-letters
GET /api/monitor/actions
GET /api/monitor/actions/export
GET /api/monitor/socket-config
POST /api/monitor/tasks/retry-batch
POST /api/monitor/tasks/retry-filtered
POST /api/monitor/tasks/{task_id}/retry
GET /api/monitor/tasks/{task_id}/lineage
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

- URL de acceso: `http://localhost:5010/login`
- URL del monitor: `http://localhost:5010/monitor`
- URL de Horizon: `http://localhost:5010/horizon`
- Funciones:
  - resumen de estados,
  - filtros por tipo, estado, origen, prioridad, queue, fechas, replay y error,
  - detalle por tarea,
  - export de tareas y auditoria,
  - replay manual de tareas `failed` y `rejected`,
  - replay por lote,
  - retry batch por filtros,
  - lineage de tareas originales y replays,
  - vista operativa de DLQ logica.

## Acceso operativo

WorkerHub ya no usa como mecanismo principal una lista local de correos. El flujo recomendado es:

1. El operador entra por `/login`.
2. WorkerHub valida credenciales contra `backoffice_service`.
3. Solo usuarios activos con `BACKOFFICE_ADMIN_ROLE_ID` autorizado pueden entrar.
4. WorkerHub crea una sesion web minima y habilita el monitor y `Horizon`.

Fallbacks:

- `WORKERHUB_OPERATIONS_TOKEN`: solo para soporte o automatizacion.
- `WORKERHUB_ALLOW_LOCAL_BYPASS=true`: solo desarrollo local/testing.

La misma sesion autenticada se reutiliza en:

- `/monitor`
- `/api/monitor/*`
- `/horizon`

## Sockets y monitoreo en tiempo real

- Broadcaster: `pusher`
- Host interno Docker: `echo-server:6001`
- Host cliente local: `localhost:6002`
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

La base de ese siguiente paso ya queda en:

- [WORKERHUB_K8S_KEDA.md](C:/laragon/www/WorkerHub/docs/WORKERHUB_K8S_KEDA.md)
- [k8s/base](C:/laragon/www/WorkerHub/k8s/base)

## Variables clave

- `QUEUE_CONNECTION=redis`
- `CACHE_DRIVER=redis`
- `REDIS_CLIENT=predis`
- `DB_CONNECTION=sqlsrv`
- `DB_HOST` y `DB_PORT` apuntando al SQL Server externo
- `KAFKA_BROKERS=redpanda:9092`
- `KAFKA_PUBLISH_ENABLED`
- `WORKERHUB_KAFKA_DIRECT_DISPATCH_FALLBACK`
- `WORKERHUB_KAFKA_SUPPRESS_PUBLISH_FAILURES`
- `KAFKA_TOPIC_REQUESTS=workerhub.tasks.requests`
- `KAFKA_TOPIC_RESULTS=workerhub.tasks.results`
- `KAFKA_TOPIC_FAILURES=workerhub.tasks.failures`
- `KAFKA_TOPIC_DEAD_LETTERS=workerhub.tasks.dead_letters`
- `BROADCAST_DRIVER=pusher`
- `PUSHER_HOST=echo-server`
- `PUSHER_PORT=6001` interno entre contenedores
- `ECHO_SERVER_PORT_FORWARD=6002` para exponer sockets al navegador/host
- `WORKERHUB_OPERATIONS_TOKEN`
- `WORKERHUB_ALLOW_TOKEN_FALLBACK`
- `WORKERHUB_ALLOW_LOCAL_BYPASS`
- `WORKERHUB_DEAD_LETTERS_ALERT_THRESHOLD`
- `BACKOFFICE_BASE_URL`
- `BACKOFFICE_AUTH_ENDPOINT`
- `BACKOFFICE_HEALTH_ENDPOINT`
- `BACKOFFICE_AUTH_TIMEOUT`
- `BACKOFFICE_ADMIN_ROLE_ID`
- `BACKOFFICE_SHARED_TOKEN`
- `EPSA_SIESA_*` para credenciales y configuracion de importacion a Siesa

## Nota sobre epsa_library

`WorkerHub` ya esta preparado para resolver `Epsalibrary\Contracts\ImportManagerInterface` desde Laravel y usarlo dentro del job `document_migration`. Solo falta completar las variables `EPSA_SIESA_*` reales del ambiente donde importaras.

El healthcheck operativo ahora valida tambien la configuracion SOAP de `epsa_library`. Mientras falten estas variables, `GET /api/health/workerhub` y `php artisan workerhub:healthcheck --json` reportaran `degraded`:

- `EPSA_SIESA_SOAP_URL`
- `EPSA_SIESA_SOAP_USER`
- `EPSA_SIESA_SOAP_PASSWORD`
- `EPSA_SIESA_SOAP_CONNECTION`

## Validacion local cerrada

Flujo validado en desarrollo:

- login real en `http://127.0.0.1:5010/login` contra `backoffice_service`
- healthcheck `ok` en `GET /api/health/workerhub`
- creacion de tarea por `POST /api/document-migrations`
- despacho en modo `direct_queue` hacia Redis cuando Kafka local no esta disponible
- procesamiento con `php artisan queue:work redis --queue=migration-default --once`
- fallo controlado de `epsa_library` por falta de configuracion SOAP real
- replay manual por `POST /api/monitor/tasks/{task_id}/retry`
- consulta de lineage por `GET /api/monitor/tasks/{task_id}/lineage`

Si quieres operar exactamente en este modo de desarrollo, usa:

```env
KAFKA_PUBLISH_ENABLED=false
WORKERHUB_KAFKA_DIRECT_DISPATCH_FALLBACK=true
WORKERHUB_KAFKA_SUPPRESS_PUBLISH_FAILURES=true
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
REDIS_CLIENT=predis
```

## Migraciones nuevas

Este flujo agrega tablas para monitoreo:

- `worker_tasks`
- `worker_task_events`
- `worker_operation_logs`

## Documentacion tecnica

- Documento funcional/tecnico: `docs/WORKERHUB_IMPLEMENTACION.md`
- Arquitectura resumida: `docs/ARQUITECTURA_WORKERHUB.md`
- Desarrollo SQL Server: `docs/WORKERHUB_SQLSERVER_DEV.md`
- Sockets y monitoreo real time: `docs/WORKERHUB_SOCKETS.md`
- Operacion y replay manual: `docs/WORKERHUB_OPERATIONS.md`
- Auth productiva contra backoffice: `docs/WORKERHUB_AUTH_BACKOFFICE.md`
- API docs con Doctum:

```bash
php vendor/bin/doctum.php update doctum.php
```

## Ver el proyecto en navegador

### Opcion Docker

1. Configura `APP_PORT=5010` en `.env.docker` o `.env`.
2. Levanta el stack:

```bash
docker compose up -d --build
```

3. Abre:

- `http://localhost:5010/login`
- `http://localhost:5010/monitor`
- `http://localhost:5010/horizon`

### Opcion sin Docker

Si solo quieres ver la UI y validar el login/monitor rapidamente:

```bash
php artisan serve --host=127.0.0.1 --port=5010
```

Luego abre:

- `http://127.0.0.1:5010/login`
- `http://127.0.0.1:5010/monitor`
- `http://127.0.0.1:5010/horizon`
