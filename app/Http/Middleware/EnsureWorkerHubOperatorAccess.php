<?php

namespace App\Http\Middleware;

use App\Services\Auth\WorkerHubOperatorSessionManager;
use App\Services\Workers\WorkerOperationLogService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkerHubOperatorAccess
{
    public function __construct(
        private readonly WorkerHubOperatorSessionManager $operatorSession,
        private readonly WorkerOperationLogService $operationLogs,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasAuthorizedSession($request) || $this->hasValidTokenFallback($request) || $this->hasLocalBypass($request)) {
            return $next($request);
        }

        $this->operationLogs->record($request, 'auth.access.denied', 'failed', null, [
            'path' => $request->path(),
            'expects_json' => $request->expectsJson(),
        ]);

        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'message' => 'No autorizado para operar WorkerHub.',
            ], 403);
        }

        return redirect()->route('workerhub.login');
    }

    private function hasAuthorizedSession(Request $request): bool
    {
        if (!$this->operatorSession->isAuthorized($request)) {
            return false;
        }

        $operator = $this->operatorSession->current($request);
        $request->attributes->set('workerhub_actor', $operator['email'] ?? null);
        $request->attributes->set('workerhub_access_channel', 'web_session');

        return true;
    }

    private function hasValidTokenFallback(Request $request): bool
    {
        if (!config('workerhub.operations.allow_token_fallback', true)) {
            return false;
        }

        $operatorToken = (string) config('workerhub.operations.access_token', '');
        if ($operatorToken === '') {
            return false;
        }

        $providedToken = (string) ($request->header('X-WorkerHub-Token') ?? $request->query('token', ''));
        if (!hash_equals($operatorToken, $providedToken)) {
            return false;
        }

        $request->attributes->set('workerhub_actor', 'token-operator');
        $request->attributes->set('workerhub_access_channel', 'token_operator');
        $this->operationLogs->record($request, 'auth.token_fallback.used', 'success', null, [
            'path' => $request->path(),
        ]);

        return true;
    }

    private function hasLocalBypass(Request $request): bool
    {
        if (!config('workerhub.operations.allow_local_bypass', true)) {
            return false;
        }

        if (!app()->environment(['local', 'testing'])) {
            return false;
        }

        if ((string) config('workerhub.operations.access_token', '') !== '') {
            return false;
        }

        $request->attributes->set('workerhub_actor', 'local-bypass');
        $request->attributes->set('workerhub_access_channel', 'local_bypass');

        return true;
    }
}
