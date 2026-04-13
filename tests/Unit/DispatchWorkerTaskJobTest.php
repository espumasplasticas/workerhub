<?php

namespace Tests\Unit;

use App\Jobs\DispatchWorkerTaskJob;
use App\Models\WorkerTask;
use App\Services\Kafka\KafkaMessageProducer;
use App\Services\Workers\WorkerTaskMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DispatchWorkerTaskJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_failure_and_dead_letter_for_terminal_failures(): void
    {
        WorkerTask::query()->create([
            'id' => 'task-failed-job',
            'type' => 'document_migration',
            'status' => 'queued',
            'priority' => 'default',
        ]);

        $producer = Mockery::mock(KafkaMessageProducer::class);
        $producer->shouldReceive('publishFailure')->once();
        $producer->shouldReceive('publishDeadLetter')->once();
        $this->app->instance(KafkaMessageProducer::class, $producer);

        $task = [
            'task_id' => 'task-failed-job',
            'type' => 'document_migration',
            'payload' => [
                'document_id' => 'DOC-500',
                'source' => 'crm',
            ],
        ];

        $job = new DispatchWorkerTaskJob($task);
        $job->failed(new \RuntimeException('Boom'));

        $this->assertDatabaseHas('worker_tasks', [
            'id' => 'task-failed-job',
            'status' => 'failed',
            'error_message' => 'Boom',
        ]);
    }
}
