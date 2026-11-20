<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TitheMemberRequest;
use App\Http\Resources\TitheMemberResource;
use App\Models\TitheMember;
use App\Services\TitheMemberImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/** Master nomor perpuluhan jemaat (data pribadi — modul tithe_members). */
class TitheMemberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $members = TitheMember::query()
            ->when($data['search'] ?? null, function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")->orWhere('alias', 'like', "%{$search}%");
                    if (preg_match('/^0*(\d+)/', $search, $m)) {
                        $q->orWhere('code', (int) $m[1]);
                    }
                });
            })
            ->orderBy('code')
            ->get();

        return TitheMemberResource::collection($members);
    }

    public function store(TitheMemberRequest $request): TitheMemberResource
    {
        return new TitheMemberResource(TitheMember::create($request->validated()));
    }

    public function update(TitheMemberRequest $request, TitheMember $titheMember): TitheMemberResource
    {
        $titheMember->update($request->validated());

        return new TitheMemberResource($titheMember);
    }

    public function destroy(TitheMember $titheMember): JsonResponse
    {
        $titheMember->delete();

        return response()->json(['message' => 'Nomor perpuluhan dihapus.']);
    }

    public function import(Request $request, TitheMemberImportService $service): JsonResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:5120']],
            ['file.required' => 'Pilih file Excel.', 'file.extensions' => 'File harus XLSX, XLS, atau CSV.', 'file.max' => 'Ukuran file maksimal 5 MB.'],
        );
        $file = $request->file('file');

        try {
            $result = $service->import($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'message' => "Import selesai: {$result['created']} baru, {$result['updated']} diperbarui, {$result['skipped']} baris dilewati.",
            'data' => $result,
        ]);
    }
}
