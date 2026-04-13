<?php

namespace App\Notifications;

use App\Models\WorkerTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkerTaskStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly WorkerTask $task)
    {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(sprintf('[WorkerHub] Tarea %s %s', $this->task->id, $this->task->status))
            ->line('Se actualizo el estado de una tarea centralizada en WorkerHub.')
            ->line('Tipo: ' . $this->task->type)
            ->line('Estado: ' . $this->task->status)
            ->line('Origen: ' . ($this->task->source ?? 'n/a'))
            ->line('Cola: ' . ($this->task->queue ?? 'n/a'))
            ->line('Mensaje de error: ' . ($this->task->error_message ?? 'n/a'))
            ->action('Ver monitor', url('/api/monitor/tasks/' . $this->task->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'type' => $this->task->type,
            'status' => $this->task->status,
            'source' => $this->task->source,
            'queue' => $this->task->queue,
            'error_message' => $this->task->error_message,
            'completed_at' => optional($this->task->completed_at)?->toIso8601String(),
            'failed_at' => optional($this->task->failed_at)?->toIso8601String(),
        ];
    }
}
