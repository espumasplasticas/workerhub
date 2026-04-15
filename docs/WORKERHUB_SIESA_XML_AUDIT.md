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

## Documento de cruce en recibos

`WorkerHub` valida por defecto que el documento de cruce del recibo exista en `SiesaEnterprise.dbo.t353_co_saldo_abierto`.

Configuracion:

- `WORKERHUB_RECEIPT_CROSS_REFERENCE_MODE=strict`
  Bloquea el recibo antes del XML final si el cruce no existe.
- `WORKERHUB_RECEIPT_CROSS_REFERENCE_MODE=warn`
  Deja continuar la importacion, registra el XML final en `siesa_web_services` y permite ver el rechazo real del WS de Siesa.

Default versionado:

- `APP_ENV=local|dev|development` => `warn`
- cualquier otro ambiente => `strict`

El valor explicito en `.env` o `.env.docker` sigue teniendo prioridad.

Para pruebas end-to-end en desarrollo donde todavia no existe el documento base en Siesa, el modo `warn` permite auditar el payload completo sin quitar la validacion en produccion.

## Uso operativo

Esto permite:

- inspeccionar el XML exacto antes de llegar a Siesa
- comparar reintentos contra el payload previo
- auditar fallos de cliente, documento de cruce y otros rechazos funcionales
- conservar el comportamiento historico de `siesa_web_services` sin depender ya de `intranetlocal`
