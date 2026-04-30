<?php

namespace App\Console\Commands;

use App\Services\Health\WorkerHubHealthService;
use Illuminate\Console\Command;

class WorkerHubHealthcheckCommand extends Command
{
    protected $signature = 'workerhub:healthcheck {--json : Imprime el payload completo}';

    protected $description = 'Ejecuta los healthchecks operativos de WorkerHub para despliegues y contenedores.';

    public function __construct(private readonly WorkerHubHealthService $healthService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $snapshot = $this->healthService->snapshot();

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf('WorkerHub health: %s', strtoupper($snapshot['status'])));
        }

        return $snapshot['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
