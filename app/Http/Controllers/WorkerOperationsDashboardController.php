<?php

namespace App\Http\Controllers;

use App\Services\Auth\WorkerHubOperatorSessionManager;
use App\Services\Workers\WorkerOperationLogService;
use App\Support\WorkerTaskProcessCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class WorkerOperationsDashboardController extends Controller
{
    public function __construct(
        private readonly WorkerOperationLogService $operationLogs,
        private readonly WorkerHubOperatorSessionManager $operatorSession,
        private readonly WorkerTaskProcessCatalog $processCatalog,
    )
    {
    }

    public function __invoke(Request $request): View
    {
        $this->operationLogs->record($request, 'monitor.view', 'success');

        return view('monitor.index', [
            'operatorToken' => (string) $request->query('token', ''),
            'operator' => $this->operatorSession->current($request),
            'accessChannel' => (string) $request->attributes->get('workerhub_access_channel', 'web'),
            'processDefinitions' => $this->processCatalog->definitions(),
        ]);
    }
}
