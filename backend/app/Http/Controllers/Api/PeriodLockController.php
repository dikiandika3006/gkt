<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodLockRequest;
use App\Models\BankReconciliation;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Services\FinanceReportService;
use App\Services\PeriodLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Tutup buku per bulan: ditutup admin/bendahara, dibuka kembali hanya oleh admin. */
class PeriodLockController extends Controller
{
    public function __construct(private PeriodLockService $locks, private FinanceReportService $report) {}

    /** 12 bulan dalam setahun beserta status tutup buku, jumlah transaksi, saldo akhir & jumlah kas yang direkonsiliasi. */
    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->endOfYear();

        $locks = PeriodLock::with('locker:id,name')->where('month', 'like', "{$year}-%")->get()->keyBy('month');
        $counts = Transaction::query()
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->selectRaw('date, COUNT(*) as total')->groupBy('date')->get()
            ->groupBy(fn ($r) => Carbon::parse($r->date)->format('Y-m'))
            ->map(fn ($rows) => (int) $rows->sum('total'));
        $reconciled = BankReconciliation::where('month', 'like', "{$year}-%")->get()->groupBy('month');

        $months = collect(range(1, 12))->map(function (int $m) use ($year, $locks, $counts, $reconciled) {
            $date = Carbon::create($year, $m, 1)->startOfDay();
            $key = $date->format('Y-m');
            $lock = $locks[$key] ?? null;
            $future = $date->isFuture();

            return [
                'month' => $key,
                'label' => $this->locks->label($date),
                'locked' => $lock !== null,
                'locked_at' => $lock?->created_at?->toIso8601String(),
                'locked_by' => $lock?->locker?->name,
                'notes' => $lock?->notes,
                'transactions' => $counts[$key] ?? 0,
                'reconciled_accounts' => isset($reconciled[$key]) ? $reconciled[$key]->count() : 0,
                'closing_balance' => $future ? null : $this->report->balance(null, $date->copy()->addMonthNoOverflow()),
                'is_future' => $future,
            ];
        });

        return response()->json(['year' => $year, 'months' => $months]);
    }

    public function store(PeriodLockRequest $request): JsonResponse
    {
        $data = $request->validated();
        PeriodLock::create([...$data, 'locked_by' => $request->user()->id]);

        return response()->json(['message' => 'Bulan '.$this->label($data['month']).' berhasil ditutup buku.'], 201);
    }

    public function destroy(string $month): JsonResponse
    {
        PeriodLock::where('month', $month)->firstOrFail()->delete();

        return response()->json(['message' => 'Tutup buku bulan '.$this->label($month).' dibuka kembali.']);
    }

    private function label(string $month): string
    {
        return $this->locks->label(Carbon::createFromFormat('Y-m-d', $month.'-01'));
    }
}
