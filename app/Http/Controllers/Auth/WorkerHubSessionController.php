<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\BackofficeAuthClientInterface;
use App\Http\Controllers\Controller;
use App\Services\Auth\WorkerHubOperatorSessionManager;
use App\Services\Workers\WorkerOperationLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class WorkerHubSessionController extends Controller
{
    public function __construct(
        private readonly BackofficeAuthClientInterface $backofficeAuth,
        private readonly WorkerHubOperatorSessionManager $operatorSession,
        private readonly WorkerOperationLogService $operationLogs,
    ) {
    }

    public function create(Request $request): View|RedirectResponse
    {
        if ($this->operatorSession->isAuthorized($request)) {
            return redirect()->route('monitor.dashboard');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->backofficeAuth->authenticateOperator(
            $credentials['username'],
            $credentials['password']
        );

        if (!$result->reachable) {
            $this->operationLogs->record($request, 'auth.login.failed', 'failed', null, [
                'reason' => 'backoffice_unavailable',
                'message' => $result->message,
            ]);

            return back()
                ->withInput($request->except('password'))
                ->withErrors(['username' => 'No fue posible validar el acceso contra backoffice_service.']);
        }

        if (!$result->authenticated) {
            $this->operationLogs->record($request, 'auth.login.failed', 'failed', null, [
                'reason' => 'invalid_credentials',
                'username' => $credentials['username'],
            ]);

            return back()
                ->withInput($request->except('password'))
                ->withErrors(['username' => 'Credenciales invalidas.']);
        }

        if (!$result->isAllowed()) {
            $this->operationLogs->record($request, 'auth.login.denied', 'failed', null, [
                'reason' => 'not_authorized',
                'username' => $credentials['username'],
                'role_id' => $result->roleId,
                'active' => $result->active,
            ]);

            return back()
                ->withInput($request->except('password'))
                ->withErrors(['username' => 'Solo operadores administradores autorizados pueden ingresar a WorkerHub.']);
        }

        $this->operatorSession->store($request, $result);
        $request->attributes->set('workerhub_actor', $result->email);
        $request->attributes->set('workerhub_access_channel', 'web_session');

        $this->operationLogs->record($request, 'auth.login.success', 'success', null, [
            'user_id' => $result->userId,
            'role_id' => $result->roleId,
        ]);

        return redirect()->intended(route('monitor.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $operator = $this->operatorSession->forget($request);

        if (is_array($operator)) {
            $request->attributes->set('workerhub_actor', $operator['email'] ?? null);
            $request->attributes->set('workerhub_access_channel', 'web_session');
        }

        $this->operationLogs->record($request, 'auth.logout', 'success', null, [
            'email' => $operator['email'] ?? null,
        ]);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('workerhub.login');
    }
}
