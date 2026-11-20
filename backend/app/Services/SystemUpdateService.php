<?php

namespace App\Services;

use App\Exceptions\SystemUpdateFailed;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Status database & "Update Database" dari Setting → About (khusus admin).
 * Melakukan hal yang sama dengan deploy/post-deploy.sh (cron hPanel): migrate + refresh cache,
 * ditambah backup otomatis sebelum migrasi.
 */
class SystemUpdateService
{
    public function __construct(private DatabaseBackupService $backups) {}

    /** Ditulis workflow deploy; hanya ada di server hasil deploy. */
    public function deployedCommitPath(): string
    {
        return base_path('DEPLOYED_COMMIT');
    }

    /** Sama dengan file penanda di post-deploy.sh. */
    public function lastAppliedPath(): string
    {
        return storage_path('app/.last_deployed');
    }

    public function status(): array
    {
        $connection = DB::connection();
        $database = ['ok' => true, 'driver' => $connection->getDriverName(), 'name' => $connection->getDatabaseName(), 'error' => null];
        $pending = [];

        try {
            $connection->getPdo();
            $pending = $this->pendingMigrations();
        } catch (Throwable $e) {
            $database['ok'] = false;
            $database['error'] = 'Tidak bisa terhubung ke database.';
        }

        $deployed = $this->readFile($this->deployedCommitPath());
        $applied = $this->readFile($this->lastAppliedPath());

        return [
            'database' => $database,
            'deployed_commit' => $deployed,
            'last_applied_commit' => $applied,
            'pending_migrations' => $pending,
            'routes_cached' => app()->routesAreCached(),
            'config_cached' => app()->configurationIsCached(),
            'needs_update' => $pending !== [] || ($deployed !== null && $deployed !== $applied),
        ];
    }

    /**
     * Backup (MySQL) → migrate → refresh cache (server deploy) → tandai commit sudah diterapkan.
     *
     * @return array{backup: ?string, migrate_output: string, steps: list<string>}
     */
    public function update(): array
    {
        return $this->backups->exclusive(function () {
            @set_time_limit(0);
            ignore_user_abort(true);
            $steps = [];

            $backup = null;
            if (DB::connection()->getDriverName() === 'mysql') {
                $backup = $this->backups->backup('_pre-update');
                $steps[] = "Backup dibuat: {$backup}";
            }

            $exit = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            $migrateOutput = trim(Artisan::output());
            if ($exit !== 0) {
                throw new SystemUpdateFailed('Migrasi database gagal.', $backup, $migrateOutput);
            }
            $steps[] = 'Migrasi database selesai.';

            $deployed = $this->readFile($this->deployedCommitPath());
            if ($deployed !== null && ! app()->environment('testing')) {
                foreach (['optimize:clear', 'config:cache', 'route:cache'] as $command) {
                    Artisan::call($command);
                }
                $steps[] = 'Cache konfigurasi & route diperbarui.';
            }

            if ($deployed !== null) {
                file_put_contents($this->lastAppliedPath(), $deployed);
            }

            return ['backup' => $backup, 'migrate_output' => $migrateOutput, 'steps' => $steps];
        });
    }

    /** @return list<string> */
    private function pendingMigrations(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_diff($files, $ran));
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $value = trim((string) file_get_contents($path));

        return $value === '' ? null : $value;
    }
}
