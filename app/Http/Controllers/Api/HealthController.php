<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Health\WorkerHubHealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private readonly WorkerHubHealthService $healthService)
    {
    }

    public function __invoke(): JsonResponse
    {
        $snapshot = $this->healthService->snapshot();

        return response()->json($snapshot, $snapshot['status'] === 'ok' ? 200 : 503);
    }
}
