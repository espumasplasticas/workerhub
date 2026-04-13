# WorkerHub en desarrollo con SQL Server

## Contexto

El proyecto `api` trabaja en desarrollo sobre conexiones `sqlsrv` separadas para ambiente dev/test. `WorkerHub` queda alineado a esa convencion:

- desarrollo local: `sqlsrv`
- bootstrap administrativo: `sqlsrv_admin`
- docker local: `sqlsrv` contra servidor externo
- pruebas automatizadas: `workerhub_test` en MySQL local

## Variables relevantes

- `DB_CONNECTION=sqlsrv`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `DB_ADMIN_HOST`
- `DB_ADMIN_PORT`
- `DB_ADMIN_DATABASE`
- `DB_ADMIN_USERNAME`
- `DB_ADMIN_PASSWORD`
- `DB_ENCRYPT`
- `DB_TRUST_SERVER_CERTIFICATE`

## Bootstrap de base en SQL Server

```bash
php artisan workerhub:bootstrap-sqlsrv-dev
```

El comando:

1. se conecta a `sqlsrv_admin`
2. crea la base configurada en `DB_DATABASE` si no existe
3. corre migraciones sobre la conexion `sqlsrv`

Si el login de desarrollo no tiene permisos sobre `master`, el comando falla con mensaje controlado y la accion correcta es pedir al DBA la creacion de la base. Despues de eso basta con correr:

```bash
php artisan migrate --database=sqlsrv --force
```

## Conexion de desarrollo

`WorkerHub` queda preparado para trabajar sobre SQL Server en local siguiendo el patron del proyecto `api`. El `docker-compose` no monta una base propia: las instancias `php`, `horizon`, `scheduler` y `kafka-consumer` salen directamente al SQL Server configurado por `DB_HOST` y `DB_PORT`.
