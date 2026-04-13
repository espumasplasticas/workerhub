<?php

namespace App\Services\Workers;

use App\Models\User;
use App\Models\WorkerTask;
use App\Notifications\WorkerTaskStatusNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

class WorkerTaskNotificationService
{
    public function notifyCompleted(WorkerTask $task): void
    {
        if (!(bool) config('workerhub.notifications.enabled') || !(bool) config('workerhub.notifications.notify_on_completed')) {
            return;
        }

        $this->send($task);
    }

    public function notifyFailed(WorkerTask $task): void
    {
        if (!(bool) config('workerhub.notifications.enabled') || !(bool) config('workerhub.notifications.notify_on_failed')) {
            return;
        }

        $this->send($task);
    }

    private function send(WorkerTask $task): void
    {
        $notification = new WorkerTaskStatusNotification($task);
        $userIds = config('workerhub.notifications.database_user_ids', []);
        $emails = config('workerhub.notifications.mail_recipients', []);

        if (is_array($userIds) && $userIds !== []) {
            $users = User::query()->whereIn('id', $userIds)->get();
            if ($users->isNotEmpty()) {
                Notification::send($users, $notification);
            }
        }

        if (is_array($emails) && $emails !== []) {
            $anonymous = new AnonymousNotifiable();
            $anonymous->route('mail', $emails)->notify($notification);
        }
    }
}
