<?php

namespace Tests\Unit;

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
}
