# WorkerHub en Kubernetes + KEDA

## Objetivo

Mover `WorkerHub` de un esquema `Docker Compose + Horizon` a uno preparado para crecimiento horizontal real:

- `Kafka` como bus de entrada y desacople.
- `queue:work` por proceso para workers PHP.
- workers externos por runtime, por ejemplo `Python`.
- `KEDA` para escalar pods por lag de Kafka o backlog Redis.

## Principio operativo

En Kubernetes no conviene usar `Horizon` como ejecutor principal si la prioridad es autoescalado horizontal por proceso.

Motivo:

- `Horizon` escala procesos dentro de un pod.
- `KEDA` escala pods.
- mezclar ambos sobre las mismas colas introduce competencia y hace menos predecible el escalado.

Por eso la propuesta queda asi:

- `WorkerHub web`: UI, API, monitor y autenticacion.
- `workerhub-kafka-consumer`: consume `workerhub.tasks.requests`.
- `workerhub-worker-receipts`: procesa `receipts-high`, `receipts-default`.
- `workerhub-worker-sales-orders`: procesa `sales-orders-high`, `sales-orders-default`.
- `workerhub-worker-invoices`: procesa `invoices-high`, `invoices-default`.
- `workerhub-worker-general`: procesa `general-*` y `migration-*`.
- `workerhub-worker-integrations`: procesa `integration`, `default`.
- `workerhub-worker-python-customers`: consume `workerhub.runtime.python.customers`.

## Lo que queda en el repo

### Imagenes listas para Kubernetes

- [Dockerfile.k8s](C:/laragon/www/WorkerHub/docker/php/Dockerfile.k8s)
- [Dockerfile.k8s](C:/laragon/www/WorkerHub/docker/nginx/Dockerfile.k8s)
- [default.k8s.conf](C:/laragon/www/WorkerHub/docker/nginx/default.k8s.conf)
- [Dockerfile](C:/laragon/www/WorkerHub/docker/python-worker/Dockerfile)
- [worker.py](C:/laragon/www/WorkerHub/docker/python-worker/worker.py)

### Manifiestos base

Todo queda bajo [k8s/base](C:/laragon/www/WorkerHub/k8s/base).

Archivos principales:

- [kustomization.yaml](C:/laragon/www/WorkerHub/k8s/base/kustomization.yaml)
- [configmap.example.yaml](C:/laragon/www/WorkerHub/k8s/base/configmap.example.yaml)
- [secret.example.yaml](C:/laragon/www/WorkerHub/k8s/base/secret.example.yaml)
- [web-deployment.yaml](C:/laragon/www/WorkerHub/k8s/base/web-deployment.yaml)
- [kafka-consumer.yaml](C:/laragon/www/WorkerHub/k8s/base/kafka-consumer.yaml)
- [worker-receipts.yaml](C:/laragon/www/WorkerHub/k8s/base/worker-receipts.yaml)
- [worker-sales-orders.yaml](C:/laragon/www/WorkerHub/k8s/base/worker-sales-orders.yaml)
- [worker-invoices.yaml](C:/laragon/www/WorkerHub/k8s/base/worker-invoices.yaml)
- [worker-general.yaml](C:/laragon/www/WorkerHub/k8s/base/worker-general.yaml)
- [worker-integrations.yaml](C:/laragon/www/WorkerHub/k8s/base/worker-integrations.yaml)
- [worker-python-customers.yaml](C:/laragon/www/WorkerHub/k8s/base/worker-python-customers.yaml)
- [migrate-job.yaml](C:/laragon/www/WorkerHub/k8s/base/migrate-job.yaml)

## Estrategia de escalado

### Kafka consumer

Escala por lag del topic:

- `workerhub.tasks.requests`

### Workers PHP

Escalan por longitud de las listas Redis:

- `queues:receipts-high`
- `queues:receipts-default`
- `queues:sales-orders-high`
- `queues:sales-orders-default`
- `queues:invoices-high`
- `queues:invoices-default`
- `queues:general-high`
- `queues:general-default`
- `queues:migration-default`
- `queues:integration`
- `queues:default`

### Worker Python

Escala por lag del topic:

- `workerhub.runtime.python.customers`

## Contrato para runtimes externos

Los workers externos deben reportar estado a `WorkerHub` usando:

- `POST /api/internal/tasks/{taskId}/status`
- header `X-WorkerHub-Shared-Token`

El token sale de:

- `WORKERHUB_RUNTIME_SHARED_TOKEN`

## Orden de despliegue

1. construir y publicar imagenes
2. crear `ConfigMap` y `Secret` reales
3. aplicar migraciones
4. desplegar workers y `ScaledObjects`

Ejemplo:

```bash
kubectl apply -f k8s/base/namespace.yaml
kubectl apply -f k8s/base/configmap.example.yaml
kubectl apply -f k8s/base/secret.example.yaml
kubectl apply -f k8s/base/migrate-job.yaml
kubectl apply -k k8s/base
```

## Requisitos externos

- cluster Kubernetes
- `KEDA` instalado
- Kafka accesible
- Redis accesible
- SQL Server accesible
- `BACKOFFICE_BASE_URL` operativo
- credenciales Siesa reales

## Decision tecnica

Para `Docker Compose`:

- `Horizon` sigue siendo valido.

Para `Kubernetes + KEDA`:

- la ruta potente y mantenible es `queue:work` por proceso + workers externos por runtime.
