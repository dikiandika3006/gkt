<?php

namespace App\Http\Controllers\Api;

use App\Enums\Module;
use App\Enums\PermissionLevel;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\FundReportItem;
use App\Models\Transaction;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Lampiran bukti (nota/kwitansi). File disimpan privat & hanya bisa diunduh user yang login. */
class AttachmentController extends Controller
{
    /** Jenis induk lampiran => [model, modul hak akses]. Tulis butuh Kelola, unduh butuh Lihat. */
    private const TYPES = [
        'transaction' => [Transaction::class, Module::Transactions],
        'fund_report_item' => [FundReportItem::class, Module::FundRequests],
    ];

    public function store(Request $request): AttachmentResource
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'attachable_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'file.mimes' => 'File harus berupa gambar (jpg/png/webp) atau PDF.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ]);

        [$class] = self::TYPES[$data['attachable_type']];
        /** @var Model $parent */
        $parent = $class::findOrFail($data['attachable_id']);
        abort_unless($this->allowedOn($request, $parent::class, $parent, PermissionLevel::Manage), 403, 'Anda tidak memiliki akses untuk aksi ini.');

        $file = $request->file('file');

        $attachment = $parent->attachments()->create([
            'path' => $file->store('attachments/'.now()->format('Y/m'), Attachment::DISK),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return new AttachmentResource($attachment);
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($this->allowed($request, $attachment, PermissionLevel::View), 403, 'Anda tidak memiliki akses untuk aksi ini.');
        abort_unless(Storage::disk(Attachment::DISK)->exists($attachment->path), 404, 'File tidak ditemukan.');

        return Storage::disk(Attachment::DISK)->response($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        abort_unless($this->allowed($request, $attachment, PermissionLevel::Manage), 403, 'Anda tidak memiliki akses untuk aksi ini.');

        $attachment->delete();

        return response()->json(['message' => 'Lampiran dihapus.']);
    }

    private function allowed(Request $request, Attachment $attachment, PermissionLevel $level): bool
    {
        return $this->allowedOn($request, $attachment->attachable_type, $attachment->attachable, $level);
    }

    /**
     * Hak atas lampiran mengikuti modul induknya; jenis tak dikenal hanya untuk admin.
     * Bukti transfer pengajuan dana (transaksi dengan fund_request_id) juga boleh DILIHAT
     * oleh pemegang hak Kas Sekretariat; mengunggah/menghapus tetap butuh Kelola Mutasi.
     */
    private function allowedOn(Request $request, string $class, ?Model $parent, PermissionLevel $level): bool
    {
        $user = $request->user();
        $module = collect(self::TYPES)->firstWhere(0, $class)[1] ?? null;

        if (! $module) {
            return $user->hasRole(UserRole::Admin);
        }

        if ($level === PermissionLevel::View && $parent instanceof Transaction && $parent->fund_request_id) {
            return $user->hasPermission(Module::Transactions) || $user->hasPermission(Module::FundRequests);
        }

        // Lampiran transaksi juga mengikuti akses kas di Mutasi.
        if ($parent instanceof Transaction && ! app(PermissionService::class)->canUseCashAccount($user, (int) $parent->cash_account_id)) {
            return false;
        }

        return $user->hasPermission($module, $level);
    }
}
