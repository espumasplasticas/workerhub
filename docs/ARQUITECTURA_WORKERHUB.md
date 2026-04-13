# WorkerHub

## Proposito

WorkerHub centraliza la recepcion, publicacion, monitoreo y ejecucion de tareas asincronas para otras aplicaciones.

## Capas

- `API Laravel`: recibe solicitudes HTTP y registra trazabilidad.
- `Kafka`: bus central de tareas entre sistemas.
- `Kafka Consumer`: traduce mensajes Kafka a jobs Laravel.
- `Redis + Horizon`: ejecuta jobs con balanceo dinamico por demanda.
- `Monitor`: persiste estado, eventos y notificaciones.
- `epsa_library`: resuelve la migracion documental a Siesa cuando la tarea es `document_migration`.

## Flujo

1. Una aplicacion cliente envia la tarea a WorkerHub.
2. WorkerHub registra la tarea en `worker_tasks`.
3. WorkerHub publica el mensaje en Kafka.
4. El consumidor Kafka recibe el mensaje y lo encola en Redis.
5. Horizon ajusta procesos y ejecuta el job.
6. WorkerHub actualiza estado, eventos y notificaciones.
7. WorkerHub publica resultado o fallo en Kafka.

## Estados

- `received`
- `published`
- `queued`
- `processing`
- `completed`
- `failed`
- `rejected`
