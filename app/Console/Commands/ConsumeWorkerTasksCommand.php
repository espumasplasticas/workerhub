<?php

namespace App\Console\Commands;

use App\Services\Kafka\WorkerTaskConsumer;
use Illuminate\Console\Command;

class ConsumeWorkerTasksCommand extends Command
{
    protected $signature = 'kafka:consume-worker-tasks';

    protected $description = 'Consume tareas desde Kafka y las encola en Redis/Horizon.';

    public function handle(WorkerTaskConsumer $consumer): int
    {
        $this->info('Starting Kafka worker task consumer...');
        $consumer->consumeRequests();

        return self::SUCCESS;
    }
}
