<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkerHubOperatorAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isAuthorized($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'message' => 'No autorizado para operar WorkerHub.',
            ], 403);
        }

        abort(403, 'No autorizado para operar WorkerHub.');
    }

    private function isAuthorized(Request $request): bool
    {
        $allowedEmails = config('workerhub.operations.allowed_emails', []);
        $operatorToken = (string) config('workerhub.operations.access_token', '');

        $user = $request->user();
        if ($user !== null && is_array($allowedEmails) && in_array($user->email, $allowedEmails, true)) {
            return true;
        }

        if ($operatorToken !== '') {
            $providedToken = (string) ($request->header('X-WorkerHub-Token') ?? $request->query('token', ''));
            return hash_equals($operatorToken, $providedToken);
        }

        return app()->environment(['local', 'testing']);
    }
}
