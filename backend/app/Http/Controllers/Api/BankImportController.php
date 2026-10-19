<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankImportRequest;
use App\Services\BankImport\BankImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Import CSV mutasi rekening bank (KlikBCA Bisnis). */
class BankImportController extends Controller
{
    public function __construct(private BankImportService $service) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
        ]);

        try {
            $preview = $this->service->preview($request->file('file')->get(), $data['cash_account_id'] ?? null);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($preview);
    }

    public function store(BankImportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $count = $this->service->commit((int) $data['cash_account_id'], $data['rows'], $request->user()->id);

        return response()->json(['message' => "{$count} transaksi berhasil diimpor.", 'count' => $count], 201);
    }
}
