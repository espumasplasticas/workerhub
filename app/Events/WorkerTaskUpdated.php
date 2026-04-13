<?php

namespace App\Events;

use App\Models\WorkerTask;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerTaskUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public WorkerTask $task,
        public string $event,
        public ?string $message = null,
        public array $context = [],
        public string $level = 'info'
    ) {
    }

    public function broadcastAs(): string
    {
        return 'worker-task.updated';
    }

    public function broadcastOn(): array
    {
        $taskChannelPrefix = (string) config('workerhub.broadcasting.task_channel_prefix', 'workerhub.tasks');

        return [
            new Channel((string) config('workerhub.broadcasting.channel', 'workerhub.monitor')),
            new Channel($taskChannelPrefix . '.' . $this->task->getKey()),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'task_id' => (string) $this->task->getKey(),
            'type' => $this->task->type,
            'source' => $this->task->source,
            'status' => $this->task->status,
            'priority' => $this->task->priority,
            'queue' => $this->task->queue,
            'attempts' => $this->task->attempts,
            'error_message' => $this->task->error_message,
            'event' => $this->event,
            'message' => $this->message,
            'level' => $this->level,
            'context' => $this->context,
            'timestamps' => [
                'requested_at' => optional($this->task->requested_at)->toIso8601String(),
                'published_at' => optional($this->task->published_at)->toIso8601String(),
                'queued_at' => optional($this->task->queued_at)->toIso8601String(),
                'processing_at' => optional($this->task->processing_at)->toIso8601String(),
                'completed_at' => optional($this->task->completed_at)->toIso8601String(),
                'failed_at' => optional($this->task->failed_at)->toIso8601String(),
            ],
        ];
    }
}
