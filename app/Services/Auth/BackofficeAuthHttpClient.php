<?php

namespace App\Services\Auth;

use App\Contracts\BackofficeAuthClientInterface;
use App\Data\Auth\BackofficeAuthResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BackofficeAuthHttpClient implements BackofficeAuthClientInterface
{
    public function authenticateOperator(string $username, string $password): BackofficeAuthResult
    {
        $baseUrl = $this->baseUrl();
        if ($baseUrl === '') {
            return BackofficeAuthResult::unavailable('BACKOFFICE_BASE_URL no configurado.');
        }

        try {
            $response = $this->client()->post($this->authEndpoint(), [
                'username' => trim($username),
                'password' => $password,
            ]);
        } catch (ConnectionException $exception) {
            return BackofficeAuthResult::unavailable('No fue posible conectar con backoffice_service.');
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return BackofficeAuthResult::unavailable('Backoffice devolvio un payload invalido.');
        }

        return BackofficeAuthResult::fromPayload($payload, $response->status());
    }

    public function health(): array
    {
        $baseUrl = $this->baseUrl();
        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status_code' => null,
                'message' => 'BACKOFFICE_BASE_URL no configurado.',
            ];
        }

        try {
            $response = $this->client()->get($this->healthEndpoint());
        } catch (ConnectionException $exception) {
            return [
                'ok' => false,
                'status_code' => 503,
                'message' => 'No fue posible conectar con backoffice_service.',
            ];
        }

        $payload = $response->json();
        $message = is_array($payload) && isset($payload['message']) ? (string) $payload['message'] : null;

        return [
            'ok' => $response->successful(),
            'status_code' => $response->status(),
            'message' => $message,
        ];
    }

    private function client()
    {
        $client = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout((float) config('workerhub.backoffice.auth_timeout', 5));

        $sharedToken = (string) config('workerhub.backoffice.shared_token', '');
        if ($sharedToken !== '') {
            $client = $client->withHeaders([
                'X-WorkerHub-Shared-Token' => $sharedToken,
            ]);
        }

        return $client;
    }

    private function baseUrl(): string
    {
        return (string) config('workerhub.backoffice.base_url', '');
    }

    private function authEndpoint(): string
    {
        return (string) config('workerhub.backoffice.auth_endpoint', '/api/internal/workerhub/operators/authenticate');
    }

    private function healthEndpoint(): string
    {
        return (string) config('workerhub.backoffice.health_endpoint', '/api/internal/workerhub/operators/health');
    }
}
