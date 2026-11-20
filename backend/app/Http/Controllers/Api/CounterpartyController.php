<?php

namespace App\Http\Controllers\Api;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\TitheMember;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saran nama jemaat / penerima (isian tetap teks bebas):
 * - data: riwayat nama di transaksi (hanya untuk yang boleh melihat Mutasi);
 * - members: master nomor perpuluhan sebagai label "001/SVN". Nama lengkap hanya untuk hak modul Nomor Perpuluhan.
 */
class CounterpartyController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($data['search'] ?? ''));
        $user = $request->user();
        $canSeeNames = $user->hasPermission(Module::TitheMembers);

        $names = $user->hasPermission(Module::Transactions)
            ? Transaction::query()
                ->whereNotNull('counterparty')
                ->when($search !== '', fn ($q) => $q->where('counterparty', 'like', "%{$search}%"))
                ->distinct()
                ->orderBy('counterparty')
                ->limit(20)
                ->pluck('counterparty')
            : collect();

        $members = TitheMember::query()
            ->where('is_active', true)
            ->when($search !== '', function ($q) use ($search, $canSeeNames) {
                // "001/SVN" → cocokkan nomor & alias; "sav" → alias (dan nama bila berhak).
                [$number, $alias] = array_pad(explode('/', $search, 2), 2, null);
                $q->where(function ($q) use ($search, $number, $alias, $canSeeNames) {
                    $q->where('alias', 'like', '%'.trim($alias ?? $search).'%');
                    if (preg_match('/^0*(\d+)$/', trim($number), $m)) {
                        $q->orWhere('code', (int) $m[1]);
                    }
                    if ($canSeeNames) {
                        $q->orWhere('name', 'like', "%{$search}%");
                    }
                });
            })
            ->orderBy('code')
            ->limit(200)
            ->get()
            ->map(fn (TitheMember $m) => ['label' => $m->label, 'name' => $canSeeNames ? $m->name : null])
            ->values();

        return response()->json(['data' => $names, 'members' => $members]);
    }
}
