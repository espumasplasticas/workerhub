<?php

namespace Tests\Unit;

use App\Data\MonitorTaskFilters;
use App\Events\WorkerTaskUpdated;
use App\Models\User;
use App\Models\WorkerTask;
use App\Notifications\WorkerTaskStatusNotification;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkerTaskMonitorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_notifies_configured_users_when_a_task_fails(): void
    {
        Event::fake([WorkerTaskUpdated::class]);
        Notification::fake();

        $user = User::query()->create([
            'name' => 'Ops',
            'email' => 'ops@example.com',
            'password' => bcrypt('secret'),
        ]);

        config()->set('workerhub.notifications.enabled', true);
        config()->set('workerhub.notifications.notify_on_failed', true);
        config()->set('workerhub.notifications.database_user_ids', [$user->id]);
        config()->set('workerhub.notifications.mail_recipients', []);

        $task = WorkerTask::query()->create([
            'id' => 'task-100',
            'type' => 'document_migration',
            'status' => 'queued',
            'priority' => 'default',
        ]);

        app(WorkerTaskMonitorService::class)->markFailed($task->id, 'Kafka timeout');

        Notification::assertSentTo($user, WorkerTaskStatusNotification::class);
        Event::assertDispatched(WorkerTaskUpdated::class, function (WorkerTaskUpdated $event) use ($task) {
            return $event->task->is($task->fresh())
                && $event->event === 'task.failed'
                && $event->level === 'error';
        });
        $this->assertDatabaseHas('worker_tasks', [
            'id' => 'task-100',
            'status' => 'failed',
            'error_message' => 'Kafka timeout',
        ]);
    }

    public function test_it_applies_extended_filters_when_listing_tasks(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-original-ok',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'default',
            'queue' => 'migration-default',
            'metadata' => [
                'process_key' => 'receipts',
                'process_label' => 'Recibos',
                'schedule_name' => 'ImportarRecibosCada1',
            ],
            'requested_at' => now()->subDays(3),
        ]);

        WorkerTask::query()->create([
            'id' => 'task-replay-hit',
            'parent_task_id' => 'task-original-ok',
            'type' => 'document_migration',
            'source' => 'crm',
            'status' => 'failed',
            'priority' => 'high',
            'queue' => 'migration-high',
            'error_message' => 'retry me',
            'metadata' => [
                'process_key' => 'receipts',
                'process_label' => 'Recibos',
                'schedule_name' => 'ImportarRecibosCada1',
            ],
            'requested_at' => now()->subDay(),
        ]);

        WorkerTask::query()->create([
            'id' => 'task-replay-miss',
            'parent_task_id' => 'task-original-ok',
            'type' => 'document_migration',
            'source' => 'erp',
            'status' => 'completed',
            'priority' => 'high',
            'queue' => 'migration-high',
            'metadata' => [
                'process_key' => 'sales_orders',
                'process_label' => 'Pedidos',
                'schedule_name' => 'ImportarPedidosCada5',
            ],
            'requested_at' => now()->subDay(),
        ]);

        $filters = MonitorTaskFilters::fromArray([
            'source' => 'crm',
            'process_key' => 'receipts',
            'schedule_name' => 'Recibos',
            'priority' => 'high',
            'queue' => 'migration-high',
            'replay_mode' => 'replays',
            'error_mode' => 'with_error',
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $result = app(WorkerTaskMonitorService::class)->listTasks($filters, 25);

        $this->assertSame(1, $result->total());
        $this->assertSame('task-replay-hit', $result->items()[0]->getKey());
    }

    public function test_it_normalizes_a_single_day_date_filter_to_that_same_day(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-today',
            'type' => 'document_migration',
            'status' => 'completed',
            'priority' => 'default',
            'requested_at' => now()->setTime(9, 30),
        ]);

        WorkerTask::query()->create([
            'id' => 'task-yesterday',
            'type' => 'document_migration',
            'status' => 'completed',
            'priority' => 'default',
            'requested_at' => now()->subDay()->setTime(9, 30),
        ]);

        $filters = MonitorTaskFilters::fromArray([
            'date_from' => now()->toDateString(),
        ]);

        $result = app(WorkerTaskMonitorService::class)->listTasks($filters, 25);

        $this->assertSame(1, $result->total());
        $this->assertSame('task-today', $result->items()[0]->getKey());
    }

    public function test_it_includes_process_summary_counts(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-receipt',
            'type' => 'document_migration',
            'status' => 'failed',
            'priority' => 'default',
            'metadata' => [
                'process_key' => 'receipts',
                'process_label' => 'Recibos',
            ],
        ]);

        WorkerTask::query()->create([
            'id' => 'task-invoice',
            'type' => 'document_migration',
            'status' => 'completed',
            'priority' => 'default',
            'metadata' => [
                'process_key' => 'invoices',
                'process_label' => 'Facturas',
            ],
        ]);

        $summary = app(WorkerTaskMonitorService::class)->summary();
        $processes = collect($summary['processes'])->keyBy('key');

        $this->assertSame(1, $processes['receipts']['total']);
        $this->assertSame(1, $processes['receipts']['failed']);
        $this->assertSame(1, $processes['invoices']['completed']);
    }
}
