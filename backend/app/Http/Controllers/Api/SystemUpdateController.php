<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SystemUpdateFailed;
use App\Http\Controllers\Controller;
use App\Services\SystemUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Setting → About: status database & tombol "Update Database" (khusus admin, rute role:admin). */
class SystemUpdateController extends Controller
{
    public function __construct(private SystemUpdateService $system) {}

    public function status(): JsonResponse
    {
        return response()->json($this->system->status());
    }

    public function update(Request $request): JsonResponse
    {
        $this->audit($request, 'dimulai');

        try {
            $result = $this->system->update();
        } catch (SystemUpdateFailed $e) {
            $this->audit($request, 'GAGAL', ['backup' => $e->backup, 'output' => $e->output]);

            return response()->json([
                'message' => $e->getMessage().($e->backup ? " Backup sebelum update: {$e->backup}." : ''),
                'backup' => $e->backup,
                'migrate_output' => $e->output,
            ], 500);
        } catch (RuntimeException $e) {
            // Mis. proses backup/restore lain sedang berjalan.
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            $this->audit($request, 'GAGAL', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Update database gagal: '.$e->getMessage()], 500);
        }

        $this->audit($request, 'selesai', ['backup' => $result['backup']]);

        return response()->json([
            'message' => 'Database sudah diperbarui.',
            ...$result,
            'status' => $this->system->status(),
        ]);
    }

    private function audit(Request $request, string $action, array $extra = []): void
    {
        Log::warning("Update database: {$action}", [
            'user_id' => $request->user()?->id,
            'user' => $request->user()?->name,
            'ip' => $request->ip(),
        ] + $extra);
    }
}
