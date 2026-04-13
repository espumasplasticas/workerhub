# Carga de tareas a Jira para WorkerHub

## Archivos listos

- CSV base: `docs/JIRA_BACKLOG_WORKERHUB.csv`
- Script PowerShell: `scripts/create_jira_issues.ps1`

## Que hace el script

- Lee el backlog desde el CSV.
- Crea cada issue en Jira Cloud dentro del proyecto indicado.
- Intenta consultar el sprint activo del board.
- Si encuentra sprint activo, agrega todos los issues creados a ese sprint.

## Requisitos

- Tener acceso al proyecto Jira.
- Tener `JIRA_EMAIL`.
- Tener `JIRA_API_TOKEN`.
- Tener PowerShell.

## Variables recomendadas

```powershell
$env:JIRA_EMAIL="tu_correo@comodisimos.com"
$env:JIRA_API_TOKEN="tu_token_atlassian"
```

## Ejecucion directa para SMWH

Desde `C:\laragon\www\WorkerHub`:

```powershell
.\scripts\create_jira_issues.ps1 `
  -BaseUrl "https://comodisimos.atlassian.net" `
  -ProjectKey "SMWH" `
  -BoardId 252 `
  -CsvPath ".\docs\JIRA_BACKLOG_WORKERHUB.csv"
```

## Ejecucion para otro proyecto o espacio

```powershell
.\scripts\create_jira_issues.ps1 `
  -BaseUrl "https://comodisimos.atlassian.net" `
  -ProjectKey "NUEVO" `
  -BoardId 999 `
  -CsvPath ".\docs\JIRA_BACKLOG_WORKERHUB.csv"
```

## Como obtener el API token

1. Ir a Atlassian Account.
2. Entrar a seguridad.
3. Crear API token.
4. Copiar el token y exportarlo como `JIRA_API_TOKEN`.

## Notas operativas

- Si no hay sprint activo en el board, los issues quedaran creados en backlog.
- Si una prioridad o tipo de issue no existe en el proyecto, Jira devolvera error en ese issue.
- El CSV actual contiene las tareas principales ya realizadas o abiertas sobre `WorkerHub`.

## Recomendacion

Primero ejecuta el script sobre un proyecto de prueba o con 1 o 2 filas del CSV para validar:

- tipos de issue,
- prioridades,
- permisos,
- y asignacion a sprint.
