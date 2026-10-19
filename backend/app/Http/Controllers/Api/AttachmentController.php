<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\FundReportItem;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Lampiran bukti (nota/kwitansi). File disimpan privat & hanya bisa diunduh user yang login. */
class AttachmentController extends Controller
{
    /** Jenis induk lampiran => [model, role yang boleh menulis]. */
    private const TYPES = [
        'transaction' => [Transaction::class, [UserRole::Admin, UserRole::Bendahara]],
        'fund_report_item' => [FundReportItem::class, [UserRole::Admin, UserRole::Bendahara, UserRole::Sekretariat]],
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

        [$class, $roles] = self::TYPES[$data['attachable_type']];
        abort_unless($request->user()->hasRole(...$roles), 403, 'Anda tidak memiliki akses untuk aksi ini.');

        /** @var Model $parent */
        $parent = $class::findOrFail($data['attachable_id']);
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

    public function download(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk(Attachment::DISK)->exists($attachment->path), 404, 'File tidak ditemukan.');

        return Storage::disk(Attachment::DISK)->response($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        $roles = collect(self::TYPES)->firstWhere(0, $attachment->attachable_type)[1] ?? [UserRole::Admin];
        abort_unless($request->user()->hasRole(...$roles), 403, 'Anda tidak memiliki akses untuk aksi ini.');

        $attachment->delete();

        return response()->json(['message' => 'Lampiran dihapus.']);
    }
}
