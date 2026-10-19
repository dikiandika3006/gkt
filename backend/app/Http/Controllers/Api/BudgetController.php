<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Anggaran tahunan per kategori + realisasinya. */
class BudgetController extends Controller
{
    public function index(Request $request, BudgetService $service): JsonResponse
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);

        return response()->json($service->realization((int) ($data['year'] ?? now()->year)));
    }

    public function store(BudgetRequest $request): BudgetResource
    {
        return new BudgetResource(Budget::create($request->validated())->load('category'));
    }

    public function update(BudgetRequest $request, Budget $budget): BudgetResource
    {
        $budget->update($request->validated());

        return new BudgetResource($budget->load('category'));
    }

    public function destroy(Budget $budget): JsonResponse
    {
        $budget->delete();

        return response()->json(['message' => 'Anggaran dihapus.']);
    }

    /** Salin anggaran dari tahun lain; baris yang sudah ada di tahun tujuan dilewati. */
    public function copy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_year' => ['required', 'integer', 'between:2000,2100'],
            'to_year' => ['required', 'integer', 'between:2000,2100', 'different:from_year'],
        ]);

        $created = DB::transaction(function () use ($data) {
            $existing = Budget::where('year', $data['to_year'])->get()
                ->map(fn (Budget $b) => $b->category_id.'|'.$b->type->value)
                ->flip();

            $count = 0;
            foreach (Budget::where('year', $data['from_year'])->get() as $source) {
                if ($existing->has($source->category_id.'|'.$source->type->value)) {
                    continue;
                }
                Budget::create([
                    'year' => $data['to_year'],
                    'category_id' => $source->category_id,
                    'type' => $source->type,
                    'amount' => $source->amount,
                    'notes' => $source->notes,
                ]);
                $count++;
            }

            return $count;
        });

        return response()->json(['message' => "{$created} anggaran disalin.", 'created' => $created]);
    }
}
