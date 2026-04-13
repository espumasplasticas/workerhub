<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'kafka' => $this->checkKafkaConfig(),
        ];

        $healthy = collect($checks)->every(fn (array $check) => ($check['ok'] ?? false) === true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'queue_connection' => config('queue.default'),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            $response = Redis::connection()->ping();

            return ['ok' => in_array($response, [true, '+PONG', 'PONG'], true)];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    private function checkKafkaConfig(): array
    {
        $brokers = (string) config('workerhub.kafka.brokers');

        return [
            'ok' => $brokers !== '',
            'brokers' => $brokers,
            'requests_topic' => config('workerhub.kafka.topics.requests'),
        ];
    }
}
