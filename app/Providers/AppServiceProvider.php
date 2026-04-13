<?php

namespace App\Providers;

use App\Contracts\BackofficeAuthClientInterface;
use App\Services\Auth\BackofficeAuthHttpClient;
use App\Services\Auth\WorkerHubOperatorSessionManager;
use App\Services\Health\WorkerHubHealthService;
use App\Services\Kafka\KafkaConfigFactory;
use App\Services\Kafka\KafkaMessageProducer;
use App\Services\Kafka\WorkerTaskConsumer;
use App\Services\Workers\DocumentMigrationService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskNotificationService;
use App\Services\Workers\WorkerOperationLogService;
use App\Services\Workers\WorkerTaskReplayService;
use App\Services\Workers\WorkerTaskRouter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BackofficeAuthClientInterface::class, BackofficeAuthHttpClient::class);
        $this->app->singleton(WorkerHubOperatorSessionManager::class);
        $this->app->singleton(WorkerHubHealthService::class);
        $this->app->singleton(KafkaConfigFactory::class);
        $this->app->singleton(KafkaMessageProducer::class);
        $this->app->singleton(DocumentMigrationService::class);
        $this->app->singleton(WorkerTaskNotificationService::class);
        $this->app->singleton(WorkerTaskMonitorService::class);
        $this->app->singleton(WorkerOperationLogService::class);
        $this->app->singleton(WorkerTaskReplayService::class);
        $this->app->singleton(WorkerTaskRouter::class);
        $this->app->singleton(WorkerTaskConsumer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
