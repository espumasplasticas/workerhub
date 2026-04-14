<?php

namespace App\Services\Auth;

use App\Data\Auth\BackofficeAuthResult;
use Illuminate\Http\Request;

class WorkerHubOperatorSessionManager
{
    /**
     * @return array<string, mixed>|null
     */
    public function current(Request $request): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $operator = $request->session()->get($this->sessionKey());

        return is_array($operator) ? $operator : null;
    }

    public function isAuthorized(Request $request): bool
    {
        $operator = $this->current($request);

        return is_array($operator) && ($operator['authorized'] ?? false) === true;
    }

    public function store(Request $request, BackofficeAuthResult $result): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->session()->put($this->sessionKey(), [
            'id' => $result->userId,
            'email' => $result->email,
            'name' => $result->name,
            'authorized' => true,
            'role_id' => $result->roleId,
            'authenticated_at' => now()->toIso8601String(),
            'access_channel' => 'web_session',
        ]);
        $request->session()->regenerate();
    }

    public function storeDevelopmentBypass(Request $request, string $username): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->session()->put($this->sessionKey(), [
            'id' => null,
            'email' => trim($username) !== '' ? trim($username) : 'local-bypass',
            'name' => 'Operador desarrollo',
            'authorized' => true,
            'role_id' => (int) config('workerhub.backoffice.admin_role_id', 20),
            'authenticated_at' => now()->toIso8601String(),
            'access_channel' => 'local_bypass',
        ]);
        $request->session()->regenerate();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forget(Request $request): ?array
    {
        $operator = $this->current($request);
        if (!$request->hasSession()) {
            return $operator;
        }

        $request->session()->forget($this->sessionKey());

        return $operator;
    }

    private function sessionKey(): string
    {
        return (string) config('workerhub.backoffice.session_key', 'workerhub.operator');
    }
}
