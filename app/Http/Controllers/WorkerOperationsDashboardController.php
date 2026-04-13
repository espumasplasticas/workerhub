<?php

namespace App\Http\Controllers;

use App\Services\Workers\WorkerOperationLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class WorkerOperationsDashboardController extends Controller
{
    public function __construct(private readonly WorkerOperationLogService $operationLogs)
    {
    }

    public function __invoke(Request $request): View
    {
        $this->operationLogs->record($request, 'monitor.view', 'success');

        return view('monitor.index', [
            'operatorToken' => (string) $request->query('token', ''),
        ]);
    }
}
