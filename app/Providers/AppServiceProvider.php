<?php

namespace App\Providers;

use App\Contracts\BackofficeAuthClientInterface;
use App\Contracts\ReceiptCrossReferenceDataSourceInterface;
use App\Contracts\ReceiptCustomerSyncDataSourceInterface;
use App\Contracts\ReceiptPreMigrationDataSourceInterface;
use App\Contracts\SiesaWebServiceLogRepositoryInterface;
use App\Repositories\EloquentSiesaWebServiceLogRepository;
use App\Services\Auth\BackofficeAuthHttpClient;
use App\Services\Auth\WorkerHubOperatorSessionManager;
use App\Services\Health\WorkerHubHealthService;
use App\Services\Kafka\KafkaConfigFactory;
use App\Services\Kafka\KafkaMessageProducer;
use App\Services\Kafka\WorkerTaskConsumer;
use App\Services\Workers\DocumentMigrationService;
use App\Services\Workers\EpsaSoapConfigurationValidator;
use App\Services\Workers\OrderMigrationService;
use App\Services\Workers\ReceiptMigrationService;
use App\Services\Workers\SiesaImportAuditService;
use App\Services\Workers\Orders\OrderCustomerSyncService;
use App\Services\Workers\Orders\OrderLegacyStateService;
use App\Services\Workers\Orders\OrderLineFactory;
use App\Services\Workers\Orders\OrderPreMigrationGuard;
use App\Services\Workers\Orders\OrderPrototypeRepository;
use App\Services\Workers\Orders\OrderSiesaStateService;
use App\Services\Workers\Receipts\ReceiptCustomerSyncLineFactory;
use App\Services\Workers\Receipts\ReceiptCustomerSyncService;
use App\Services\Workers\Receipts\ReceiptCrossReferenceGuard;
use App\Services\Workers\Receipts\ReceiptLegacyStateService;
use App\Services\Workers\Receipts\ReceiptLineFactory;
use App\Services\Workers\Receipts\ReceiptPreMigrationGuard;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use App\Services\Workers\Receipts\ReceiptSiesaStateService;
use App\Services\Workers\Receipts\SqlReceiptCrossReferenceDataSource;
use App\Services\Workers\Receipts\SqlReceiptCustomerSyncDataSource;
use App\Services\Workers\Receipts\SqlReceiptPreMigrationDataSource;
use App\Services\Workers\WorkerOperationLogService;
use App\Services\Workers\WorkerTaskDispatchService;
use App\Services\Workers\WorkerTaskMonitorService;
use App\Services\Workers\WorkerTaskNotificationService;
use App\Services\Workers\WorkerTaskReplayEligibilityService;
use App\Services\Workers\WorkerTaskReplayService;
use App\Services\Workers\WorkerTaskRouter;
use Epsalibrary\Application\Imports\ImportBatchBuilder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BackofficeAuthClientInterface::class, BackofficeAuthHttpClient::class);
        $this->app->singleton(ReceiptPreMigrationDataSourceInterface::class, SqlReceiptPreMigrationDataSource::class);
        $this->app->singleton(ReceiptCustomerSyncDataSourceInterface::class, SqlReceiptCustomerSyncDataSource::class);
        $this->app->singleton(ReceiptCrossReferenceDataSourceInterface::class, SqlReceiptCrossReferenceDataSource::class);
        $this->app->singleton(SiesaWebServiceLogRepositoryInterface::class, EloquentSiesaWebServiceLogRepository::class);
        $this->app->singleton(WorkerHubOperatorSessionManager::class);
        $this->app->singleton(WorkerHubHealthService::class);
        $this->app->singleton(KafkaConfigFactory::class);
        $this->app->singleton(KafkaMessageProducer::class);
        $this->app->singleton(ImportBatchBuilder::class);
        $this->app->singleton(EpsaSoapConfigurationValidator::class);
        $this->app->singleton(SiesaImportAuditService::class);
        $this->app->singleton(DocumentMigrationService::class);
        $this->app->singleton(OrderPrototypeRepository::class);
        $this->app->singleton(OrderLineFactory::class);
        $this->app->singleton(OrderPreMigrationGuard::class);
        $this->app->singleton(OrderCustomerSyncService::class);
        $this->app->singleton(OrderSiesaStateService::class);
        $this->app->singleton(OrderLegacyStateService::class);
        $this->app->singleton(OrderMigrationService::class);
        $this->app->singleton(ReceiptPrototypeRepository::class);
        $this->app->singleton(ReceiptLineFactory::class);
        $this->app->singleton(ReceiptCustomerSyncLineFactory::class);
        $this->app->singleton(ReceiptPreMigrationGuard::class);
        $this->app->singleton(ReceiptCrossReferenceGuard::class);
        $this->app->singleton(ReceiptCustomerSyncService::class);
        $this->app->singleton(ReceiptSiesaStateService::class);
        $this->app->singleton(ReceiptLegacyStateService::class);
        $this->app->singleton(ReceiptMigrationService::class);
        $this->app->singleton(WorkerTaskDispatchService::class);
        $this->app->singleton(WorkerTaskNotificationService::class);
        $this->app->singleton(WorkerTaskMonitorService::class);
        $this->app->singleton(WorkerOperationLogService::class);
        $this->app->singleton(WorkerTaskReplayEligibilityService::class);
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
