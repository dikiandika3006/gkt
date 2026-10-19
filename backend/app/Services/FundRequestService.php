<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\FundReport;
use App\Models\FundReportItem;
use App\Models\FundRequest;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Mekanisme uang muka (kas kecil) per kas, mis. Kas Sekretariat:
 * pengajuan → transfer (mutasi antar kas) → LPJ per nota (pengeluaran dari kas tsb) → sisa dibawa.
 */
class FundRequestService
{
    public function __construct(private TransferService $transfers) {}

    public function load(FundRequest $request): FundRequest
    {
        return $request->load([
            'cashAccount', 'creator:id,name', 'items',
            'transfers', 'report.items.category', 'report.items.attachments', 'report.items.transaction:id,fund_report_item_id,number',
        ]);
    }

    /**
     * Sisa dana dibawa dari periode sebelumnya = saldo awal kas + semua mutasi kas tsb
     * yang bukan milik pengajuan periode ini atau sesudahnya.
     * Positif = masih ada sisa di kas; negatif = kas menalangi (kurang bayar).
     */
    public function carryOver(FundRequest $request): float
    {
        $laterRequestIds = FundRequest::where('cash_account_id', $request->cash_account_id)
            ->where('period', '>=', $request->period)
            ->pluck('id');

        $periodStart = $request->period.'-01';

        $mutation = (float) Transaction::query()
            ->where('cash_account_id', $request->cash_account_id)
            ->where(function ($q) use ($laterRequestIds, $periodStart) {
                $q->where(fn ($q) => $q->whereNull('fund_request_id')->whereDate('date', '<', $periodStart))
                    ->orWhere(fn ($q) => $q->whereNotNull('fund_request_id')->whereNotIn('fund_request_id', $laterRequestIds));
            })
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) as total")
            ->value('total');

        return round((float) $request->cashAccount->opening_balance + $mutation, 2);
    }

    /** Ringkasan angka pengajuan. Relasi harus sudah di-load lewat load(). */
    public function summary(FundRequest $request): array
    {
        $requested = $request->requestedAmount();
        $transferred = $request->transferredAmount();
        $reported = $request->reportedAmount();
        $carryOver = $this->carryOver($request);
        $suggested = round(max(0, $requested - $carryOver), 2);

        return [
            'requested' => $requested,
            'carry_over' => $carryOver,
            'suggested_transfer' => $suggested,
            'transferred' => $transferred,
            'remaining_transfer' => round(max(0, $suggested - $transferred), 2),
            'reported' => $reported,
            // Selisih dana periode ini (transfer − realisasi): positif = sisa, negatif = kurang.
            'difference' => round($transferred - $reported, 2),
            // Saldo kas setelah periode ini = dibawa ke pengajuan berikutnya.
            'closing_balance' => round($carryOver + $transferred - $reported, 2),
            'status' => $request->status()->value,
            'status_label' => $request->status()->label(),
        ];
    }

    /** @param array{date: string, from_cash_account_id: int, amount: float|string, description?: ?string, reference?: ?string} $data */
    public function addTransfer(FundRequest $request, array $data, ?int $userId): void
    {
        TransferService::ensureAccountsDiffer((int) $data['from_cash_account_id'], $request->cash_account_id);

        $this->transfers->create([
            ...$data,
            'to_cash_account_id' => $request->cash_account_id,
            'description' => $data['description'] ?? "Transfer dana {$request->cashAccount->name} {$request->number}",
            'fund_request_id' => $request->id,
        ], $userId);
    }

    public function report(FundRequest $request, ?int $userId): FundReport
    {
        return $request->report()->firstOrCreate([], ['created_by' => $userId]);
    }

    /** Simpan satu nota LPJ beserta transaksi pengeluarannya di kas pengajuan. */
    public function saveItem(FundRequest $request, array $data, ?int $userId, ?FundReportItem $item = null): FundReportItem
    {
        return DB::transaction(function () use ($request, $data, $userId, $item) {
            $report = $this->report($request, $userId);
            $amount = round((float) $data['qty'] * (float) $data['unit_price'], 2);
            $fields = [...$data, 'amount' => $amount];

            $item ? $item->update($fields) : $item = $report->items()->create($fields);

            $transactionData = [
                'date' => $item->date,
                'type' => TransactionType::Expense,
                'category_id' => $item->category_id,
                'cash_account_id' => $request->cash_account_id,
                'amount' => $amount,
                'description' => "LPJ {$request->number}: {$item->description}",
                'fund_request_id' => $request->id,
            ];

            $transaction = $item->transaction()->first();
            if ($transaction) {
                $transaction->update($transactionData);
            } else {
                Transaction::lockNumbering();
                Transaction::create([...$transactionData, 'fund_report_item_id' => $item->id, 'created_by' => $userId]);
            }

            return $item;
        });
    }

    public function deleteItem(FundReportItem $item): void
    {
        DB::transaction(function () use ($item) {
            $item->transaction()->first()?->delete();
            $item->attachments->each->delete();
            $item->delete();
        });
    }

    /**
     * Laporan detail pemakaian dana per periode: ringkasan per pengajuan,
     * pemakaian per kategori × bulan, dan daftar nota.
     */
    public function periodReport(int $cashAccountId, string $startPeriod, string $endPeriod): array
    {
        $requests = FundRequest::query()
            ->where('cash_account_id', $cashAccountId)
            ->whereBetween('period', [$startPeriod, $endPeriod])
            ->with(['cashAccount', 'items', 'transfers', 'report.items.category'])
            ->orderBy('period')
            ->get();

        $periods = $requests->pluck('period')->values();
        $emptyPeriods = $periods->mapWithKeys(fn ($p) => [$p => 0.0])->all();

        $items = $requests->flatMap(fn (FundRequest $r) => ($r->report?->items ?? collect())->map(fn (FundReportItem $i) => [
            'id' => $i->id,
            'period' => $r->period,
            'request_number' => $r->number,
            'date' => $i->date->toDateString(),
            'category_id' => $i->category_id,
            'category' => $i->category?->name,
            'description' => $i->description,
            'qty' => (float) $i->qty,
            'unit' => $i->unit,
            'unit_price' => (float) $i->unit_price,
            'amount' => (float) $i->amount,
        ]))->values();

        $byCategory = $items->groupBy('category_id')->map(function ($rows) use ($emptyPeriods) {
            $periods = $emptyPeriods;
            foreach ($rows as $row) {
                $periods[$row['period']] = round($periods[$row['period']] + $row['amount'], 2);
            }

            return [
                'category_id' => $rows->first()['category_id'],
                'category' => $rows->first()['category'],
                'periods' => $periods,
                'total' => round(array_sum($periods), 2),
            ];
        })->sortByDesc('total')->values();

        return [
            'periods' => $periods,
            'requests' => $requests->map(fn (FundRequest $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'period' => $r->period,
                ...$this->summary($r),
            ])->values(),
            'by_category' => $byCategory,
            'items' => $items,
            'total_reported' => round((float) $items->sum('amount'), 2),
        ];
    }

    /** Hapus pengajuan beserta transfer & LPJ-nya. */
    public function delete(FundRequest $request): void
    {
        DB::transaction(function () use ($request) {
            Transaction::where('fund_request_id', $request->id)->whereNotNull('transfer_group')
                ->pluck('transfer_group')->unique()
                ->each(fn (string $group) => $this->transfers->delete($group));

            $request->report?->items->each(fn (FundReportItem $item) => $this->deleteItem($item));
            $request->delete();
        });
    }
}
