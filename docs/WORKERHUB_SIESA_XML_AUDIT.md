# Auditoria previa de XML hacia Siesa

## Objetivo

Antes de enviar cualquier importacion a Siesa, `WorkerHub` arma el XML final y lo registra en la tabla `siesa_web_services`. Esto replica el comportamiento legacy de `intranetlocal`, pero agrega contexto operativo propio de `WorkerHub`.

## Tabla

La migracion [`2026_04_15_000005_create_siesa_web_services_table.php`](C:/laragon/www/SistemaGL/workerhub_stage/database/migrations/2026_04_15_000005_create_siesa_web_services_table.php) crea:

- `xml`: XML final listo para importar
- `result`: `null` mientras esta pendiente, `1` si Siesa responde exito, `0` si responde error o lanza excepcion
- `result_text`: mensaje consolidado de respuesta/error
- `ts`: marca de tiempo del registro previo
- `processed_at`: cuando ya se conocio el resultado
- `worker_task_id`, `task_type`, `document_id`, `source`, `import_stage`, `context`: metadata de WorkerHub para trazabilidad

## Flujo

`SiesaImportAuditService` hace este orden:

1. construye el XML con `ImportBatchBuilder`
2. inserta el registro pendiente en `siesa_web_services`
3. ejecuta la importacion real con `epsa_library`
4. actualiza `result`, `result_text` y `processed_at`

Si la importacion lanza excepcion, el registro igual queda marcado como fallido.

## Alcance actual

Se registra XML previo para:

- `document_migration`
- `receipt_customer_sync`
- `receipt_migration`

## Uso operativo

Esto permite:

- inspeccionar el XML exacto antes de llegar a Siesa
- comparar reintentos contra el payload previo
- auditar fallos de cliente, documento de cruce y otros rechazos funcionales
- conservar el comportamiento historico de `siesa_web_services` sin depender ya de `intranetlocal`
