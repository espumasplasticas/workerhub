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
            'requested_at' => now()->subDay(),
        ]);

        $filters = MonitorTaskFilters::fromArray([
            'source' => 'crm',
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
}
