<?php

namespace App\Console\Commands;

use Illuminate\Database\QueryException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class BootstrapSqlServerDevelopmentCommand extends Command
{
    protected $signature = 'workerhub:bootstrap-sqlsrv-dev
                            {--database= : Nombre de la base a crear y migrar}
                            {--connection=sqlsrv : Conexion destino de Laravel}
                            {--admin-connection=sqlsrv_admin : Conexion administrativa para crear la base}';

    protected $description = 'Crea la base WorkerHub en SQL Server para desarrollo y ejecuta migraciones.';

    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        $adminConnection = (string) $this->option('admin-connection');
        $databaseName = (string) ($this->option('database') ?: config("database.connections.{$connection}.database"));

        if ($databaseName === '') {
            $this->error('No hay nombre de base configurado para la conexion destino.');

            return self::FAILURE;
        }

        $safeDatabaseName = str_replace(']', ']]', $databaseName);
        $this->info(sprintf('Creating database [%s] on SQL Server if it does not exist...', $databaseName));

        try {
            DB::connection($adminConnection)->unprepared(
                "IF DB_ID(N'{$safeDatabaseName}') IS NULL CREATE DATABASE [{$safeDatabaseName}]"
            );
        } catch (QueryException $exception) {
            $this->error('No fue posible crear la base en SQL Server.');
            $this->line($exception->getMessage());
            $this->newLine();
            $this->warn('Accion requerida:');
            $this->line(sprintf('- Solicitar al DBA la creacion de la base [%s].', $databaseName));
            $this->line(sprintf('- Luego ejecutar: php artisan migrate --database=%s --force', $connection));

            return self::FAILURE;
        }

        DB::purge($connection);

        $this->info(sprintf('Running migrations on connection [%s]...', $connection));
        $exitCode = Artisan::call('migrate', [
            '--database' => $connection,
            '--force' => true,
        ]);

        $this->output->write(Artisan::output());

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
