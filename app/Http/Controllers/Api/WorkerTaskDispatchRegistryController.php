<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workers\WorkerTaskDispatchRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerTaskDispatchRegistryController extends Controller
{
    public function __construct(private readonly WorkerTaskDispatchRegistryService $registry)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'in:receipt,order,invoice'],
            'document_id' => ['required', 'string'],
            'event_type' => ['nullable', 'string'],
        ]);

        $record = $this->registry->findAccepted(
            (string) $validated['entity_type'],
            trim((string) $validated['document_id']),
            trim((string) ($validated['event_type'] ?? 'migration'))
        );

        return response()->json([
            'exists' => $record !== null,
            'entity_type' => $validated['entity_type'],
            'document_id' => trim((string) $validated['document_id']),
            'event_type' => trim((string) ($validated['event_type'] ?? 'migration')),
            'task_id' => $record?->task_id,
            'accepted_at' => $record?->accepted_at?->toIso8601String(),
        ]);
    }
}
