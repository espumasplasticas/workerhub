<#
.synopsis
  Encola y procesa una migración de factura de forma controlada.

.description
  Usa la API local de WorkerHub para encolar una tarea de tipo invoice_migration
  y luego ejecuta un worker para procesar un job (modo seguro: --once).

.parameters
  -InvoiceId (int) : invoice_id (rowid) en la BD de origen (preferido)
  -DbConnection (string) : conexión configurada en config('workerhub.invoices.source_connections') (default: sqlsrv)
  -CreatedByUserId (int) : id de usuario para notificaciones al API (default: 285)
  -Host (string) : base URL del servicio WorkerHub (default http://localhost:8000)
  -ProcessOnce (switch) : si se setea, lanza `php artisan queue:work --once` para procesar el job en cola

.examples
  .\scripts\run-invoice-migration.ps1 -InvoiceId 24787 -DbConnection sqlsrv -ProcessOnce
#>
param(
    [Parameter(Mandatory=$false)] [int]$InvoiceId,
    [Parameter(Mandatory=$false)] [string]$DbConnection = 'sqlsrv',
    [Parameter(Mandatory=$false)] [int]$CreatedByUserId = 285,
    [Parameter(Mandatory=$false)] [string]$Host = 'http://localhost:8000',
    [switch]$ProcessOnce
)

if (-not $InvoiceId) {
    Write-Host "Error: Debes indicar -InvoiceId. Ejemplo: -InvoiceId 24787" -ForegroundColor Red
    exit 2
}

$payload = @{
    invoice_id = $InvoiceId
    db_connection = $DbConnection
    created_by_user_id = $CreatedByUserId
    source = 'api'
} | ConvertTo-Json

Write-Host "Encolando invoice_id=$InvoiceId db_connection=$DbConnection en $Host/api/invoice-migrations"

try {
    $response = Invoke-RestMethod -Method Post -Uri "$Host/api/invoice-migrations" -Body $payload -ContentType 'application/json' -Headers @{Accept='application/json'} -ErrorAction Stop
} catch {
    Write-Host "Error llamando API: $_" -ForegroundColor Red
    exit 3
}

Write-Host "API response:"; $response | ConvertTo-Json -Depth 5 | Write-Host

if ($response.accepted -ne $true) {
    Write-Host "La tarea no fue aceptada por la API. Revisa el mensaje y los logs." -ForegroundColor Yellow
    exit 0
}

if ($ProcessOnce) {
    Write-Host "Procesando 1 job en cola (modo seguro) ..." -ForegroundColor Cyan
    # For safety use testing env and queue:work --once; adapt if you want different queue driver
    $env:APP_ENV = 'testing'
    # Prefer database queue for controlled processing; ensure jobs table exists if you use database driver
    $env:QUEUE_CONNECTION = 'database'

    & php artisan queue:work --once --queue=invoices-default,invoices-high --tries=1 --timeout=1800
    $exit = $LASTEXITCODE
    if ($exit -ne 0) {
        Write-Host "queue:work finalizo con codigo $exit" -ForegroundColor Yellow
    } else {
        Write-Host "Worker procesado (once). Revisa logs y siesa_web_services para auditoria." -ForegroundColor Green
    }
}

Write-Host "Hecho." -ForegroundColor Green
