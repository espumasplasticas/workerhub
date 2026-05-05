# WorkerHub Sockets

## Objetivo

`WorkerHub` expone eventos en tiempo real para que un monitor Laravel, otro backend o una SPA pueda seguir el ciclo de vida de las tareas sin consultar el API en polling permanente.

## Arquitectura

- Laravel publica eventos de estado cuando una tarea cambia.
- El broadcaster configurado es `pusher`.
- `laravel-echo-server` corre en Docker como `echo-server`.
- Redis funciona como backend de pub/sub para el servidor de sockets.

## Canales

- Canal general: `workerhub.monitor`
- Canal por tarea: `workerhub.tasks.{task_id}`

## Evento emitido

Nombre del evento:

- `worker-task.updated`

Payload principal:

- `task_id`
- `type`
- `source`
- `status`
- `priority`
- `queue`
- `attempts`
- `error_message`
- `event`
- `message`
- `level`
- `context`
- `timestamps`

## Endpoint de descubrimiento

Para evitar hardcodear host/puerto/canales en otras aplicaciones:

```http
GET /api/monitor/socket-config
```

Respuesta:

```json
{
  "broadcaster": "pusher",
  "key": "workerhub-key",
  "host": "localhost",
  "port": 6002,
  "scheme": "http",
  "cluster": "mt1",
  "channels": {
    "monitor": "workerhub.monitor",
    "task_prefix": "workerhub.tasks"
  }
}
```

## Cliente Laravel Echo

Ejemplo de suscripcion:

```js
window.Echo.channel('workerhub.monitor')
    .listen('.worker-task.updated', (event) => {
        console.log(event);
    });
```

Canal por tarea:

```js
window.Echo.channel(`workerhub.tasks.${taskId}`)
    .listen('.worker-task.updated', (event) => {
        console.log(event);
    });
```

## Nota operativa

Kafka sigue siendo el bus central de tareas. Los sockets no reemplazan Kafka; solo exponen el estado del procesamiento en tiempo real para observabilidad y UX.
