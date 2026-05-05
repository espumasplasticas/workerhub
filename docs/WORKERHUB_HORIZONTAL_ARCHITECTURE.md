# WorkerHub Horizontal Architecture

## Objetivo

Dejar `WorkerHub` preparado para crecimiento por proceso y por runtime:

- `Kafka` como bus de entrada y desacople.
- `Horizon` para workers PHP sobre Redis.
- workers externos, por ejemplo `Python`, consumiendo topics dedicados por proceso.
- monitoreo y trazabilidad centralizados en `WorkerHub`.

## Flujo recomendado

1. `api` crea el documento y notifica a `WorkerHub`.
2. `WorkerHub` registra la tarea y la publica al topic general de ingreso:
   - `workerhub.tasks.requests`
3. `kafka-consumer` resuelve el `process_key`, el `runtime` y el plan de ejecucion.
4. Si el runtime es `php`:
   - encola en Redis/Horizon en la cola del proceso.
5. Si el runtime es externo, por ejemplo `python`:
   - delega a un topic de ejecucion dedicado:
   - ejemplo: `workerhub.runtime.python.customers`
6. El worker externo procesa y reporta estado a `WorkerHub`:
   - `POST /api/internal/tasks/{taskId}/status`
   - header `X-WorkerHub-Shared-Token`

## Colas por proceso

Quedaron separadas por proceso para permitir afinacion independiente:

- `receipts-default`
- `receipts-high`
- `sales-orders-default`
- `sales-orders-high`
- `invoices-default`
- `invoices-high`
- `customers-default`
- `customers-high`
- `general-default`
- `general-high`
- `integration`

## Supervisores Horizon

Quedaron supervisores separados para:

- `supervisor-receipts`
- `supervisor-sales-orders`
- `supervisor-invoices`
- `supervisor-customers`
- `supervisor-general`
- `supervisor-integrations`

Esto permite:

- aislar backlog por proceso,
- ajustar `minProcesses` y `maxProcesses` por proceso,
- crecer un proceso sin arrastrar a los otros.

## Runtime externo Python

`WorkerHub` ya puede delegar tareas a runtime externo por Kafka si el proceso se configura con:

- `runtime=python`
- `topics.execution=workerhub.runtime.python.<process>`

Contrato esperado del worker Python:

1. consume el topic de ejecucion del proceso.
2. procesa la tarea.
3. reporta a `WorkerHub`:

```http
POST /api/internal/tasks/{taskId}/status
X-WorkerHub-Shared-Token: <WORKERHUB_RUNTIME_SHARED_TOKEN>
Content-Type: application/json
```

Payload ejemplo:

```json
{
  "status": "completed",
  "result": {
    "runtime": "python",
    "message": "Task processed successfully"
  }
}
```

Estados soportados:

- `queued`
- `processing`
- `completed`
- `failed`
- `rejected`

## Escalado

### Lo que si resuelve hoy

- Horizon escala procesos PHP dentro del contenedor.
- Kafka desacopla productores y consumidores.
- las colas por proceso permiten afinacion independiente.

### Lo que no resuelve Docker Compose por si solo

- autoescalado horizontal real de contenedores
- encender y apagar replicas automaticamente por demanda

## Recomendacion de siguiente nivel

Para autoescalado horizontal real:

- `Kubernetes`
- `KEDA`

Escalar por:

- lag de Kafka por topic
- longitud de cola Redis por proceso
- backlog de tareas programadas

Arquitectura objetivo:

- `php-workers-receipts`
- `php-workers-invoices`
- `python-workers-customers`
- `python-workers-analytics`

Cada uno con escalado independiente.

## Estado del repo

El repo ya incluye una base concreta para ese siguiente nivel:

- manifiestos en [k8s/base](C:/laragon/www/WorkerHub/k8s/base)
- imagen PHP para Kubernetes en [Dockerfile.k8s](C:/laragon/www/WorkerHub/docker/php/Dockerfile.k8s)
- imagen Nginx para Kubernetes en [Dockerfile.k8s](C:/laragon/www/WorkerHub/docker/nginx/Dockerfile.k8s)
- worker Python base en [worker.py](C:/laragon/www/WorkerHub/docker/python-worker/worker.py)
- guia operativa en [WORKERHUB_K8S_KEDA.md](C:/laragon/www/WorkerHub/docs/WORKERHUB_K8S_KEDA.md)
