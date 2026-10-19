<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringExpenseRequest;
use App\Http\Resources\RecurringExpenseResource;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Anggaran biaya rutin bulanan. */
class RecurringExpenseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RecurringExpenseResource::collection(
            RecurringExpense::with('category')->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    /** Realisasi per item untuk satu bulan + kecukupan dana. */
    public function status(Request $request, RecurringExpenseService $service): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);

        return response()->json($service->status($data['period'] ?? now()->format('Y-m')));
    }

    public function store(RecurringExpenseRequest $request): RecurringExpenseResource
    {
        return new RecurringExpenseResource(RecurringExpense::create($request->validated())->load('category'));
    }

    public function show(RecurringExpense $recurringExpense): RecurringExpenseResource
    {
        return new RecurringExpenseResource($recurringExpense->load('category'));
    }

    public function update(RecurringExpenseRequest $request, RecurringExpense $recurringExpense): RecurringExpenseResource
    {
        $recurringExpense->update($request->validated());

        return new RecurringExpenseResource($recurringExpense->load('category'));
    }

    public function destroy(RecurringExpense $recurringExpense): JsonResponse
    {
        if ($recurringExpense->transactions()->withTrashed()->exists()) {
            abort(422, 'Biaya rutin sudah punya riwayat pembayaran. Nonaktifkan saja.');
        }

        $recurringExpense->delete();

        return response()->json(['message' => 'Biaya rutin dihapus.']);
    }
}
