<?php

namespace App\Services;

use App\Enums\CategoryNature;
use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Perhitungan saldo & rekap keuangan. Semua query memakai SQL portabel
 * (MySQL di aplikasi, SQLite di test) — jangan pakai fungsi tanggal spesifik DB.
 *
 * Aturan hitung:
 * - Pemasukan/pengeluaran "operasional" = kategori ber-nature operational (dasar surplus/defisit).
 * - Titipan & pinjaman dilaporkan terpisah.
 * - Mutasi antar kas: saling meniadakan untuk gereja secara keseluruhan; hanya muncul saat laporan difilter per kas.
 */
class FinanceReportService
{
    private const SIGNED_AMOUNT = "CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE -transactions.amount END";

    /** Saldo sebelum tanggal $before (saldo awal akun + mutasi sebelum tanggal tsb). Null = saldo saat ini. */
    public function balance(?int $cashAccountId = null, ?Carbon $before = null): float
    {
        $opening = (float) CashAccount::query()
            ->when($cashAccountId, fn ($q) => $q->whereKey($cashAccountId))
            ->sum('opening_balance');

        $mutation = (float) $this->baseQuery($cashAccountId)
            ->when($before, fn ($q) => $q->whereDate('date', '<', $before))
            ->selectRaw('COALESCE(SUM('.self::SIGNED_AMOUNT.'), 0) as total')
            ->value('total');

        return round($opening + $mutation, 2);
    }

    /** @return Collection<int, array{id:int, code:string, name:string, type:string, balance:float}> */
    public function balancesPerAccount(): Collection
    {
        $mutations = Transaction::query()
            ->selectRaw('cash_account_id, COALESCE(SUM('.self::SIGNED_AMOUNT.'), 0) as total')
            ->groupBy('cash_account_id')
            ->pluck('total', 'cash_account_id');

        return CashAccount::query()->orderBy('code')->get()->map(fn (CashAccount $account) => [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'balance' => round((float) $account->opening_balance + (float) ($mutations[$account->id] ?? 0), 2),
        ]);
    }

    /**
     * Total periode, dipisah operasional / titipan & pinjaman / mutasi antar kas.
     *
     * @return array{income: float, expense: float, net: float, non_operational_in: float, non_operational_out: float, transfer_in: float, transfer_out: float, change: float}
     */
    public function totals(Carbon $start, Carbon $end, ?int $cashAccountId = null): array
    {
        $rows = $this->periodQuery($start, $end, $cashAccountId)
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->selectRaw('categories.nature as nature, transactions.type as type, SUM(transactions.amount) as total')
            ->groupBy('categories.nature', 'transactions.type')
            ->get();

        $sum = function (array $natures, TransactionType $type) use ($rows): float {
            return round((float) $rows
                ->filter(fn ($r) => in_array($r->nature, $natures, true) && $this->typeValue($r->type) === $type->value)
                ->sum('total'), 2);
        };

        $nonOperational = [CategoryNature::Titipan->value, CategoryNature::Pinjaman->value];
        $transfer = [CategoryNature::Transfer->value];

        $income = $sum([CategoryNature::Operational->value], TransactionType::Income);
        $expense = $sum([CategoryNature::Operational->value], TransactionType::Expense);
        $nonIn = $sum($nonOperational, TransactionType::Income);
        $nonOut = $sum($nonOperational, TransactionType::Expense);
        $transferIn = $sum($transfer, TransactionType::Income);
        $transferOut = $sum($transfer, TransactionType::Expense);

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => round($income - $expense, 2),
            'non_operational_in' => $nonIn,
            'non_operational_out' => $nonOut,
            'transfer_in' => $transferIn,
            'transfer_out' => $transferOut,
            // Perubahan saldo kas total pada periode ini.
            'change' => round($income - $expense + $nonIn - $nonOut + $transferIn - $transferOut, 2),
        ];
    }

    /** Rekap per kategori, dipisah pemasukan & pengeluaran. Mutasi antar kas hanya ikut jika difilter per kas. */
    public function byCategory(Carbon $start, Carbon $end, ?int $cashAccountId = null): array
    {
        $rows = $this->periodQuery($start, $end, $cashAccountId)
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->when(! $cashAccountId, fn ($q) => $q->where('categories.nature', '!=', CategoryNature::Transfer->value))
            ->selectRaw('categories.id, categories.code, categories.name, categories.nature, transactions.type, SUM(transactions.amount) as total, COUNT(*) as count')
            ->addSelect('categories.group as category_group')
            ->groupBy('categories.id', 'categories.code', 'categories.name', 'categories.group', 'categories.nature', 'transactions.type')
            ->orderBy('categories.code')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'group' => $row->category_group,
                'nature' => $row->nature,
                'type' => $this->typeValue($row->type),
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->count,
            ]);

        return [
            'income' => $rows->where('type', TransactionType::Income->value)->values(),
            'expense' => $rows->where('type', TransactionType::Expense->value)->values(),
        ];
    }

    /** Pengeluaran operasional per kategori, diurutkan terbesar, dengan persentase & rata-rata per bulan. */
    public function categoryRanking(Carbon $start, Carbon $end): Collection
    {
        $rows = $this->byCategory($start, $end)['expense']
            ->where('nature', CategoryNature::Operational->value)
            ->sortByDesc('total')
            ->values();

        $grandTotal = (float) $rows->sum('total');
        $months = max(1, $this->monthKeys($start, $end)->count());

        return $rows->map(fn (array $row, int $i) => [
            ...$row,
            'rank' => $i + 1,
            'percentage' => $grandTotal > 0 ? round($row['total'] / $grandTotal * 100, 2) : 0,
            'monthly_average' => round($row['total'] / $months, 2),
        ]);
    }

    /** Tren pemasukan/pengeluaran operasional per bulan untuk $months bulan terakhir. */
    public function monthlyTrend(int $months = 12): Collection
    {
        $start = now()->startOfMonth()->subMonths($months - 1);
        $end = now()->endOfMonth();
        $matrix = $this->monthlyMatrix($start, $end);

        return collect($matrix['months'])->map(fn (array $m) => [
            'month' => $m['month'],
            'income' => $m['income'],
            'expense' => $m['expense'],
        ]);
    }

    /**
     * Rekap bulanan: ringkasan per bulan + matriks kelompok/kategori × bulan.
     * Pengganti sheet Rekap Bulanan / Laporan Kas Umum / Analisa bagian 2.
     */
    public function monthlyMatrix(Carbon $start, Carbon $end, ?int $cashAccountId = null): array
    {
        $keys = $this->monthKeys($start, $end);

        // Agregasi per tanggal di DB, digabung per bulan di PHP agar portabel antar DB.
        $daily = $this->periodQuery($start, $end, $cashAccountId)
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->when(! $cashAccountId, fn ($q) => $q->where('categories.nature', '!=', CategoryNature::Transfer->value))
            ->selectRaw('transactions.date as trx_date, transactions.type as trx_type, categories.id as category_id, categories.code, categories.name, categories.nature, SUM(transactions.amount) as total')
            ->addSelect('categories.group as category_group')
            ->groupBy('transactions.date', 'transactions.type', 'categories.id', 'categories.code', 'categories.name', 'categories.group', 'categories.nature')
            ->get()
            ->map(fn ($r) => [
                'month' => Carbon::parse($r->trx_date)->format('Y-m'),
                'type' => $this->typeValue($r->trx_type),
                'category_id' => (int) $r->category_id,
                'code' => $r->code,
                'name' => $r->name,
                'group' => $r->category_group,
                'nature' => $r->nature,
                'total' => (float) $r->total,
            ]);

        $emptyMonths = $keys->mapWithKeys(fn ($k) => [$k => 0.0])->all();

        $section = function (string $type) use ($daily, $emptyMonths) {
            return $daily->where('type', $type)
                ->groupBy(fn ($r) => $r['nature'].'|'.$r['group'])
                ->map(function (Collection $groupRows) use ($emptyMonths) {
                    $first = $groupRows->first();
                    $categories = $groupRows->groupBy('category_id')->map(function (Collection $catRows) use ($emptyMonths) {
                        $months = $emptyMonths;
                        foreach ($catRows as $r) {
                            $months[$r['month']] = round($months[$r['month']] + $r['total'], 2);
                        }

                        return [
                            'category_id' => $catRows->first()['category_id'],
                            'code' => $catRows->first()['code'],
                            'name' => $catRows->first()['name'],
                            'months' => $months,
                            'total' => round(array_sum($months), 2),
                        ];
                    })->sortBy('code')->values();

                    $months = $emptyMonths;
                    foreach ($categories as $c) {
                        foreach ($c['months'] as $k => $v) {
                            $months[$k] = round($months[$k] + $v, 2);
                        }
                    }

                    return [
                        'group' => $first['group'],
                        'nature' => $first['nature'],
                        'months' => $months,
                        'total' => round(array_sum($months), 2),
                        'categories' => $categories,
                    ];
                })
                ->sortBy(fn ($g) => ($g['nature'] === CategoryNature::Operational->value ? '0' : '1').$g['group'])
                ->values();
        };

        $balance = $this->balance($cashAccountId, $start);
        $openingBalance = $balance;

        $summary = $keys->map(function (string $key) use (&$balance, $cashAccountId) {
            $monthStart = Carbon::createFromFormat('Y-m-d', $key.'-01')->startOfDay();
            $totals = $this->totals($monthStart, $monthStart->copy()->endOfMonth(), $cashAccountId);
            $opening = $balance;
            $balance = round($balance + $totals['change'], 2);

            return ['month' => $key, 'opening_balance' => $opening, ...$totals, 'closing_balance' => $balance];
        });

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $balance,
            'months' => $summary->values(),
            'income' => $section(TransactionType::Income->value),
            'expense' => $section(TransactionType::Expense->value),
        ];
    }

    /**
     * Ketahanan kas: saldo saat ini dibagi rata-rata pengeluaran operasional per bulan
     * pada $months bulan penuh terakhir.
     *
     * @return array{balance: float, average_expense: float, months: float|null}
     */
    public function cashResilience(int $months = 6): array
    {
        $start = now()->startOfMonth()->subMonths($months);
        $end = now()->startOfMonth()->subDay()->endOfDay();
        $average = round($this->totals($start, $end)['expense'] / $months, 2);
        $balance = $this->balance();

        return [
            'balance' => $balance,
            'average_expense' => $average,
            'months' => $average > 0 ? round($balance / $average, 1) : null,
        ];
    }

    /** Buku kas: saldo awal, daftar transaksi dengan saldo berjalan, saldo akhir. */
    public function ledger(Carbon $start, Carbon $end, ?int $cashAccountId = null): array
    {
        $opening = $this->balance($cashAccountId, $start);
        $running = $opening;

        $rows = $this->periodQuery($start, $end, $cashAccountId)
            ->with(['category:id,code,name', 'cashAccount:id,code,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (Transaction $t) use (&$running) {
                $amount = (float) $t->amount;
                $running += $t->type === TransactionType::Income ? $amount : -$amount;

                return [
                    'id' => $t->id,
                    'number' => $t->number,
                    'date' => $t->date->toDateString(),
                    'description' => $t->description,
                    'counterparty' => $t->counterparty,
                    'category' => $t->category?->name,
                    'cash_account' => $t->cashAccount?->name,
                    'income' => $t->type === TransactionType::Income ? $amount : 0,
                    'expense' => $t->type === TransactionType::Expense ? $amount : 0,
                    'balance' => round($running, 2),
                ];
            });

        return [
            'opening_balance' => $opening,
            'rows' => $rows,
            'closing_balance' => round($running, 2),
        ];
    }

    /** @return Collection<int, string> daftar 'Y-m' dari $start s/d $end */
    public function monthKeys(Carbon $start, Carbon $end): Collection
    {
        $keys = collect();
        for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonth()) {
            $keys->push($m->format('Y-m'));
        }

        return $keys;
    }

    private function typeValue(mixed $type): string
    {
        return $type instanceof TransactionType ? $type->value : (string) $type;
    }

    private function baseQuery(?int $cashAccountId = null): Builder
    {
        return Transaction::query()
            ->when($cashAccountId, fn ($q) => $q->where('transactions.cash_account_id', $cashAccountId));
    }

    private function periodQuery(Carbon $start, Carbon $end, ?int $cashAccountId = null): Builder
    {
        return $this->baseQuery($cashAccountId)
            ->whereDate('transactions.date', '>=', $start)
            ->whereDate('transactions.date', '<=', $end);
    }
}
