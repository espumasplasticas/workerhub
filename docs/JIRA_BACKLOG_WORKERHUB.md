# Backlog Jira sugerido para SMWH

## Limitacion actual

En esta sesion no hay conector Jira ni autenticacion Atlassian disponible, por lo tanto no puedo crear issues reales ni asignarlos al sprint activo de `SMWH`.

## Archivos listos

- Importacion CSV: `docs/JIRA_BACKLOG_WORKERHUB.csv`

## Recomendacion operativa

1. Importar el CSV en Jira o usarlo para carga manual.
2. Asignar todos los issues creados al sprint activo del board `SMWH`.
3. Si quieres automatizarlo de verdad en siguientes sesiones, necesito una de estas dos rutas:
   - un conector MCP/Jira habilitado en el entorno,
   - o credenciales/API token con un script autorizado para crear issues via REST.

## Issues incluidos

- Docker base con Nginx y dos instancias PHP.
- Conexion a SQL Server externo desde Docker.
- Bootstrap de base SQL Server.
- Ingreso de tareas por Laravel y publicacion a Kafka.
- Consumo Kafka y encolamiento Redis/Horizon.
- Monitor persistente de tareas y eventos.
- Broadcasting por sockets para monitoreo en tiempo real.
- Echo Server en Docker.
- Notificaciones por fallos/completados.
- Integracion con `epsa_library` para migracion a Siesa.
- Documentacion tecnica y Doctum.
- Pruebas unitarias y feature del flujo principal.
