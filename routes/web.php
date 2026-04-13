<?php

use App\Http\Controllers\WorkerOperationsDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware('workerhub.operator')->group(function () {
    Route::get('/', WorkerOperationsDashboardController::class)->name('monitor.dashboard');
    Route::get('/monitor', WorkerOperationsDashboardController::class);
});
