<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\ExcelExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $this->validateFilters($request);

        $transactions = $this->filteredQuery($filters)
            ->with(['category', 'cashAccount', 'creator:id,name'])
            ->withCount('attachments')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return TransactionResource::collection($transactions);
    }

    /** Export daftar transaksi (filter sama dengan index) ke Excel, kolom mengikuti sheet "Data Mutasi". */
    public function export(Request $request, ExcelExportService $excel): StreamedResponse
    {
        $filters = $this->validateFilters($request);

        $rows = $this->filteredQuery($filters)
            ->with(['category:id,name,group', 'cashAccount:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $t) => [
                $t->date->format('Y-m'),
                $t->date->toDateString(),
                $t->number,
                (string) $t->category?->group,
                (string) $t->category?->name,
                (string) $t->counterparty,
                $t->description,
                $t->type === TransactionType::Income ? (float) $t->amount : 0,
                $t->type === TransactionType::Expense ? (float) $t->amount : 0,
                (string) $t->cashAccount?->name,
                (string) $t->reference,
                (string) $t->notes,
            ])
            ->all();

        $period = trim(($filters['start_date'] ?? '').' s/d '.($filters['end_date'] ?? ''));

        return $excel->download('transaksi-'.now()->format('Ymd-His').'.xlsx', [[
            'title' => 'Data Mutasi',
            'heading' => ['Data Mutasi', $period === 's/d' ? 'Semua periode' : "Periode {$period}"],
            'headers' => ['Periode', 'Tanggal', 'Nomor', 'Kelompok', 'Kategori', 'Nama', 'Keterangan', 'Masuk', 'Keluar', 'Kas', 'Referensi', 'Catatan'],
            'rows' => $rows,
            'money_from' => 7,
        ]]);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'category_id' => ['nullable', 'integer'],
            'cash_account_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'recurring_expense_id' => ['nullable', 'integer'],
            'recurring_period' => ['nullable', 'date_format:Y-m'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function filteredQuery(array $filters): Builder
    {
        return Transaction::query()
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($filters['cash_account_id'] ?? null, fn ($q, $v) => $q->where('cash_account_id', $v))
            ->when($filters['recurring_expense_id'] ?? null, fn ($q, $v) => $q->where('recurring_expense_id', $v))
            ->when($filters['recurring_period'] ?? null, fn ($q, $v) => $q->where('recurring_period', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('description', 'like', "%{$v}%")
                    ->orWhere('number', 'like', "%{$v}%")
                    ->orWhere('reference', 'like', "%{$v}%")
                    ->orWhere('counterparty', 'like', "%{$v}%");
            }));
    }

    public function store(TransactionRequest $request): TransactionResource
    {
        // Lock agar nomor transaksi tidak bentrok saat input bersamaan.
        $transaction = DB::transaction(function () use ($request) {
            Transaction::lockNumbering();

            return Transaction::create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
            ]);
        });

        return new TransactionResource($transaction->load(['category', 'cashAccount', 'creator:id,name']));
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(['category', 'cashAccount', 'creator:id,name', 'attachments']));
    }

    public function update(TransactionRequest $request, Transaction $transaction): TransactionResource
    {
        $transaction->update($request->validated());

        return new TransactionResource($transaction->load(['category', 'cashAccount', 'creator:id,name']));
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        if ($transaction->isManaged()) {
            abort(422, 'Transaksi ini dikelola dari modul Mutasi Antar Kas / LPJ. Hapus dari sana.');
        }

        $transaction->delete();

        return response()->json(['message' => 'Transaksi dihapus.']);
    }
}
