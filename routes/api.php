<?php

use App\Http\Controllers\Api\DocumentMigrationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderMigrationController;
use App\Http\Controllers\Api\ReceiptMigrationController;
use App\Http\Controllers\Api\WorkerTaskLifecycleController;
use App\Http\Controllers\Api\WorkerTaskMonitorController;
use App\Http\Controllers\Api\WorkerTaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/health/workerhub', HealthController::class);
Route::post('/worker-tasks', [WorkerTaskController::class, 'store']);
Route::post('/document-migrations', [DocumentMigrationController::class, 'store']);
Route::post('/order-migrations', [OrderMigrationController::class, 'store']);
Route::post('/receipt-migrations', [ReceiptMigrationController::class, 'store']);
Route::post('/internal/tasks/{taskId}/status', [WorkerTaskLifecycleController::class, 'update']);
Route::middleware(['web', 'workerhub.operator'])->group(function () {
    Route::get('/monitor/tasks', [WorkerTaskMonitorController::class, 'index']);
    Route::get('/monitor/tasks/export', [WorkerTaskMonitorController::class, 'exportTasks']);
    Route::get('/monitor/tasks/summary', [WorkerTaskMonitorController::class, 'summary']);
    Route::get('/monitor/dead-letters', [WorkerTaskMonitorController::class, 'deadLetters']);
    Route::get('/monitor/dead-letters/export', [WorkerTaskMonitorController::class, 'exportDeadLetters']);
    Route::get('/monitor/actions', [WorkerTaskMonitorController::class, 'actions']);
    Route::get('/monitor/actions/export', [WorkerTaskMonitorController::class, 'exportActions']);
    Route::get('/monitor/socket-config', [WorkerTaskMonitorController::class, 'socketConfig']);
    Route::post('/monitor/tasks/retry-batch', [WorkerTaskMonitorController::class, 'retryBatch']);
    Route::post('/monitor/tasks/retry-filtered', [WorkerTaskMonitorController::class, 'retryFiltered']);
    Route::post('/monitor/tasks/{taskId}/retry', [WorkerTaskMonitorController::class, 'retry']);
    Route::get('/monitor/tasks/{taskId}/lineage', [WorkerTaskMonitorController::class, 'lineage']);
    Route::get('/monitor/tasks/{taskId}', [WorkerTaskMonitorController::class, 'show']);
});
