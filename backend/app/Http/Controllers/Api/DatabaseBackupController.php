<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackupRestoreRequest;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Form rahasia Backup & Restore database (About → Alt+F7), khusus admin.
 * Selain unlock, wajib PIN (config/backup.php); status terbuka disimpan per token login.
 */
class DatabaseBackupController extends Controller
{
    public function __construct(private DatabaseBackupService $backups) {}

    public function unlock(Request $request): JsonResponse
    {
        $pin = (string) config('backup.pin');
        abort_if($pin === '', 403, 'Fitur backup belum diaktifkan (BACKUP_PIN kosong di .env server).');

        if (! hash_equals($pin, (string) $request->input('pin'))) {
            $this->audit($request, 'PIN salah');

            return response()->json(['message' => 'PIN salah.'], 422);
        }

        $minutes = (int) config('backup.unlock_minutes', 15);
        Cache::put($this->unlockKey($request), true, now()->addMinutes($minutes));

        return response()->json(['ok' => true, 'minutes' => $minutes]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureUnlocked($request);

        return response()->json([
            'backups' => $this->backups->list(),
            'database_size' => $this->backups->databaseSize(),
            'upload_limit' => min($this->iniBytes('upload_max_filesize'), $this->iniBytes('post_max_size')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureUnlocked($request);

        try {
            $name = $this->backups->exclusive(fn () => $this->backups->backup());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Backup gagal: '.$e->getMessage()], 500);
        }

        $this->audit($request, 'backup', $name);

        return response()->json(['name' => $name], 201);
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $this->ensureUnlocked($request);
        $path = $this->pathOr404($name);
        $this->audit($request, 'download', $name);

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(Request $request, string $name): JsonResponse
    {
        $this->ensureUnlocked($request);
        $this->pathOr404($name);
        $this->backups->delete($name);
        $this->audit($request, 'hapus', $name);

        return response()->json(['message' => 'File backup dihapus.']);
    }

    public function restore(BackupRestoreRequest $request): JsonResponse
    {
        $this->ensureUnlocked($request);

        // Semua pemeriksaan file dilakukan sebelum data apa pun diubah.
        try {
            if ($request->hasFile('file')) {
                $upload = $request->file('file');
                if (! preg_match('/\.sql(\.gz)?$/i', $upload->getClientOriginalName())) {
                    throw new RuntimeException('File harus berformat .sql atau .sql.gz.');
                }
                $path = $upload->getRealPath();
                $source = 'upload: '.$upload->getClientOriginalName();
            } else {
                $path = $this->backups->path((string) $request->input('name'));
                $source = (string) $request->input('name');
            }
            $this->backups->validate($path);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage().' Database tidak diubah.'], 422);
        }

        try {
            $result = $this->backups->exclusive(function () use ($path) {
                // Maintenance dulu: tidak ada transaksi baru yang lolos dari backup pengaman di bawah.
                Artisan::call('down', ['--retry' => 30]);
                try {
                    $preRestore = $this->backups->backup('_pre-restore');
                    $statements = $this->backups->restore($path);

                    // Koneksi baru (SQL_MODE dari file tidak terbawa), lalu naikkan skema ke versi aplikasi saat ini.
                    DB::purge();
                    DB::reconnect();
                    Artisan::call('migrate', ['--force' => true]);
                    $migrate = trim(Artisan::output());
                } finally {
                    Artisan::call('up');
                }

                return compact('preRestore', 'statements', 'migrate');
            });
        } catch (Throwable $e) {
            report($e);
            $this->audit($request, 'restore GAGAL', $source);

            return response()->json([
                'message' => 'Restore gagal: '.$e->getMessage()
                    .' Bila data rusak, restore ulang dari file backup "_pre-restore" terbaru di riwayat.',
            ], 500);
        }

        $this->audit($request, 'restore', $source, ['pre_restore' => $result['preRestore'], 'statements' => $result['statements']]);

        return response()->json([
            'message' => "Restore selesai ({$result['statements']} perintah). Backup sebelum restore: {$result['preRestore']}. Silakan login ulang.",
            'migrate' => $result['migrate'],
        ]);
    }

    private function unlockKey(Request $request): string
    {
        $token = $request->user()->currentAccessToken();

        // Per token login: PIN harus dimasukkan ulang di perangkat/sesi lain.
        return 'backup_unlock:'.$request->user()->id.':'.($token instanceof PersonalAccessToken ? $token->getKey() : 'session');
    }

    private function ensureUnlocked(Request $request): void
    {
        if ((string) config('backup.pin') === '' || ! Cache::get($this->unlockKey($request))) {
            abort(403, 'Sesi form backup sudah berakhir. Masukkan PIN lagi.');
        }
    }

    private function pathOr404(string $name): string
    {
        try {
            return $this->backups->path($name);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    /** Catat aksi backup ke log aplikasi (user ikut dicatat sebelum data ditimpa restore). */
    private function audit(Request $request, string $action, ?string $file = null, array $extra = []): void
    {
        Log::warning("Backup database: {$action}", [
            'user_id' => $request->user()?->id,
            'user' => $request->user()?->name,
            'file' => $file,
            'ip' => $request->ip(),
        ] + $extra);
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
