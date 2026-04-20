# WorkerHub y API: ejecucion segura de pruebas

## 1. Regla operativa

No ejecutar pruebas que usen:

- `RefreshDatabase`
- `DatabaseTransactions`
- `migrate:fresh`
- conexiones `sqlsrv` reales

si no estan aisladas a una base dedicada de testing.

## 2. Estado actual seguro

### `api`

- `phpunit.xml` fija `DB_CONNECTION=test`
- `api/.env.testing` ya documenta que `test` debe apuntar a `192.168.0.74`
- las pruebas nuevas de pedidos se dejaron como:
  - unitarias puras
  - source tests
  - sin `RefreshDatabase`

### `WorkerHub`

- `phpunit.xml` fija `DB_CONNECTION=mysql`
- `WorkerHub/.env.testing` fuerza:
  - `DB_CONNECTION=mysql`
  - `DB_DATABASE=workerhub_test`
- las pruebas nuevas de pedidos se dejaron como:
  - unitarias puras
  - sin `RefreshDatabase`
  - sin `sqlsrv` real

## 3. Binario PHP correcto

En este entorno el `php` por defecto apunta a 7.2 y no sirve para estos proyectos.

Usar:

```powershell
C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe
```

## 4. Comandos seguros

### API

```powershell
C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe artisan test --filter "DispatchOrderMigrationToWorkerHubTest|OrderServiceSourceTest|DatabaseSellOrderCreatorSourceTest"
```

### WorkerHub

```powershell
C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe artisan test --filter "OrderMigrationServiceTest|OrderMigrationControllerTest|WorkerTaskReplayEligibilityServiceTest|DispatchWorkerTaskJobOrderNotificationTest"
```

## 5. Suites que requieren endurecimiento adicional

Inventario detectado con `RefreshDatabase` o riesgo de base:

### `api`

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/EmailVerificationTest.php`
- `tests/Feature/PasswordResetTest.php`
- `tests/Feature/PasswordConfirmationTest.php`
- `tests/Feature/RegistrationTest.php`

### `WorkerHub`

- `tests/Feature/ReceiptMigrationControllerTest.php`
- `tests/Feature/WorkerTaskControllerTest.php`
- `tests/Feature/WorkerTaskMonitorControllerTest.php`
- `tests/Feature/WorkerHubHealthControllerTest.php`
- `tests/Feature/WorkerHubOperatorAccessTest.php`
- `tests/Feature/WorkerHubAuthenticationTest.php`
- `tests/Feature/WorkerOperationsDashboardTest.php`
- `tests/Unit/DispatchWorkerTaskJobTest.php`
- `tests/Unit/WorkerTaskMonitorServiceTest.php`

## 6. Politica recomendada

- No correr `php artisan test` completo en desarrollo compartido.
- Correr solo filtros de pruebas aisladas.
- Cualquier prueba nueva de integracion debe usar:
  - mocks
  - doubles
  - source tests
  - controladores instanciados
- Si una prueba necesita persistencia real, debe usar solo una base de testing dedicada y nunca `laravel_comodisimos` ni `source_sqlsrv`.

