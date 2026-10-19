<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Autocomplete nama jemaat / penerima dari riwayat transaksi. */
class CounterpartyController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $names = Transaction::query()
            ->whereNotNull('counterparty')
            ->when($data['search'] ?? null, fn ($q, $v) => $q->where('counterparty', 'like', "%{$v}%"))
            ->distinct()
            ->orderBy('counterparty')
            ->limit(20)
            ->pluck('counterparty');

        return response()->json(['data' => $names]);
    }
}
