<?php

namespace App\Services\Health;

use App\Contracts\BackofficeAuthClientInterface;
use App\Models\WorkerTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class WorkerHubHealthService
{
    public function __construct(private readonly BackofficeAuthClientInterface $backofficeAuth)
    {
    }

    /**
     * @return array{status: string, app: string, queue_connection: string|null, checks: array<string, array<string, mixed>>, alerts: array<int, string>}
     */
    public function snapshot(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'kafka' => $this->checkKafkaConfig(),
            'backoffice' => $this->checkBackoffice(),
            'siesa_import' => $this->checkSiesaImportConfig(),
            'dead_letters' => $this->checkDeadLetters(),
        ];

        $criticalChecks = collect($checks)->filter(fn (array $check): bool => ($check['critical'] ?? true) === true);
        $healthy = $criticalChecks->every(fn (array $check): bool => ($check['ok'] ?? false) === true);

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'queue_connection' => config('queue.default'),
            'checks' => $checks,
            'alerts' => $this->buildAlerts($checks),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true, 'critical' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'critical' => true, 'error' => $exception->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            $response = Redis::connection()->ping();
            $normalizedResponse = is_string($response)
                ? strtoupper($response)
                : (is_object($response) && method_exists($response, '__toString')
                    ? strtoupper((string) $response)
                    : $response);

            return [
                'ok' => in_array($normalizedResponse, [true, '+PONG', 'PONG'], true),
                'critical' => true,
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'critical' => true, 'error' => $exception->getMessage()];
        }
    }

    private function checkKafkaConfig(): array
    {
        $brokers = (string) config('workerhub.kafka.brokers');
        $publishEnabled = (bool) config('workerhub.kafka.publish_enabled', true);
        $directDispatchFallback = (bool) config('workerhub.kafka.direct_dispatch_fallback', false);

        return [
            'ok' => $brokers !== '',
            'critical' => true,
            'brokers' => $brokers,
            'requests_topic' => config('workerhub.kafka.topics.requests'),
            'publish_enabled' => $publishEnabled,
            'direct_dispatch_fallback' => $directDispatchFallback,
            'dispatch_mode' => $publishEnabled ? 'kafka' : ($directDispatchFallback ? 'direct_queue' : 'disabled'),
        ];
    }

    private function checkBackoffice(): array
    {
        $health = $this->backofficeAuth->health();

        return [
            'ok' => (bool) ($health['ok'] ?? false),
            'critical' => (bool) config('workerhub.backoffice.health_critical', true),
            'status_code' => $health['status_code'] ?? null,
            'message' => $health['message'] ?? null,
        ];
    }

    private function checkDeadLetters(): array
    {
        try {
            $count = WorkerTask::query()
                ->whereIn('status', ['failed', 'rejected'])
                ->count();
            $threshold = max(1, (int) config('workerhub.health.dead_letters_alert_threshold', 25));

            return [
                'ok' => $count < $threshold,
                'critical' => false,
                'count' => $count,
                'threshold' => $threshold,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'critical' => false,
                'count' => null,
                'threshold' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function checkSiesaImportConfig(): array
    {
        $soap = (array) config('epsa_library.soap', []);
        $url = trim((string) ($soap['url'] ?? ''));
        $user = trim((string) ($soap['user'] ?? ''));
        $password = trim((string) ($soap['password'] ?? ''));
        $connection = trim((string) ($soap['connection'] ?? ''));

        $missing = [];

        if ($url === '') {
            $missing[] = 'EPSA_SIESA_SOAP_URL';
        }

        if ($user === '') {
            $missing[] = 'EPSA_SIESA_SOAP_USER';
        }

        if ($password === '') {
            $missing[] = 'EPSA_SIESA_SOAP_PASSWORD';
        }

        if ($connection === '') {
            $missing[] = 'EPSA_SIESA_SOAP_CONNECTION';
        }

        if ($missing !== []) {
            return [
                'ok' => false,
                'critical' => true,
                'message' => 'Configuracion SOAP de epsa_library incompleta.',
                'missing' => $missing,
                'soap_url' => $url !== '' ? $url : null,
            ];
        }

        if (!$this->isAbsoluteHttpUrl($url)) {
            return [
                'ok' => false,
                'critical' => true,
                'message' => 'EPSA_SIESA_SOAP_URL debe ser una URL absoluta HTTP(S).',
                'missing' => [],
                'soap_url' => $url,
            ];
        }

        return [
            'ok' => true,
            'critical' => true,
            'message' => 'Configuracion SOAP de epsa_library lista para importacion.',
            'soap_url' => $url,
        ];
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @param array<string, array<string, mixed>> $checks
     * @return array<int, string>
     */
    private function buildAlerts(array $checks): array
    {
        $alerts = [];

        if (($checks['backoffice']['ok'] ?? false) === false) {
            $alerts[] = 'La dependencia de autenticacion backoffice esta degradada.';
        }

        if (($checks['database']['ok'] ?? false) === false) {
            $alerts[] = 'La conexion a SQL Server no esta disponible.';
        }

        if (($checks['kafka']['ok'] ?? false) === false) {
            $alerts[] = 'La configuracion de Kafka no es valida.';
        }

        if (($checks['siesa_import']['ok'] ?? false) === false) {
            $alerts[] = 'La configuracion SOAP de epsa_library no esta lista para migraciones documentales.';
        }

        if (($checks['dead_letters']['ok'] ?? true) === false) {
            if (isset($checks['dead_letters']['count'], $checks['dead_letters']['threshold'])) {
                $alerts[] = sprintf(
                    'La bandeja de dead letters supero el umbral operativo (%d/%d).',
                    (int) ($checks['dead_letters']['count'] ?? 0),
                    (int) ($checks['dead_letters']['threshold'] ?? 0)
                );
            } else {
                $alerts[] = 'No fue posible evaluar la bandeja de dead letters.';
            }
        }

        return $alerts;
    }
}
