<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Budget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Anggaran vs realisasi tahun berjalan.
 * Realisasi = transaksi kategori & arah yang sama (mutasi antar kas tidak dihitung, lihat FinanceReportService::byCategory).
 */
class BudgetService
{
    /** Persentase di atas jadwal yang dianggap "waspada" untuk anggaran pengeluaran. */
    public const PACE_TOLERANCE = 0.10;

    /** Ambang minimal persentase terpakai sebelum status "waspada" muncul. */
    public const WARNING_PERCENTAGE = 80;

    public function __construct(private FinanceReportService $report) {}

    /**
     * @return array{year: int, elapsed: float, rows: Collection<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function realization(int $year, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();
        $elapsed = $this->elapsedFraction($year, $asOf);

        $actuals = collect();
        if ($elapsed > 0) {
            $byCategory = $this->report->byCategory($start, $asOf->lt($end) ? $asOf->copy()->endOfDay() : $end);
            $actuals = $byCategory['income']->concat($byCategory['expense'])
                ->keyBy(fn (array $row) => $row['category_id'].'|'.$row['type']);
        }

        $rows = Budget::query()
            ->with('category:id,code,name,group,type,nature')
            ->where('year', $year)
            ->get()
            ->sortBy(fn (Budget $b) => [$b->type->value === 'income' ? 0 : 1, $b->category?->code])
            ->values()
            ->map(function (Budget $budget) use ($actuals, $elapsed) {
                $amount = (float) $budget->amount;
                $actual = (float) ($actuals[$budget->category_id.'|'.$budget->type->value]['total'] ?? 0);
                $expected = round($amount * $elapsed, 2);
                $percentage = $amount > 0 ? round($actual / $amount * 100, 1) : 0;

                return [
                    'id' => $budget->id,
                    'year' => $budget->year,
                    'category_id' => $budget->category_id,
                    'category' => $budget->category?->only('id', 'code', 'name', 'group'),
                    'type' => $budget->type->value,
                    'type_label' => $budget->type->label(),
                    'amount' => $amount,
                    'notes' => $budget->notes,
                    'actual' => round($actual, 2),
                    'expected_to_date' => $expected,
                    'percentage' => $percentage,
                    'remaining' => round($amount - $actual, 2),
                    'status' => $this->status($budget->type, $amount, $actual, $expected, $percentage),
                ];
            });

        $sum = fn (string $type, string $field) => round((float) $rows->where('type', $type)->sum($field), 2);

        return [
            'year' => $year,
            'elapsed' => round($elapsed, 4),
            'rows' => $rows,
            'totals' => [
                'expense_budget' => $sum('expense', 'amount'),
                'expense_actual' => $sum('expense', 'actual'),
                'income_budget' => $sum('income', 'amount'),
                'income_actual' => $sum('income', 'actual'),
            ],
        ];
    }

    /**
     * Pengeluaran: aman | waspada (sudah ≥80% & melaju di atas jadwal) | lewat (>100%).
     * Penerimaan: tercapai | aman | tertinggal (di bawah jadwal lebih dari toleransi).
     */
    private function status(TransactionType $type, float $amount, float $actual, float $expected, float $percentage): string
    {
        if ($type === TransactionType::Income) {
            return match (true) {
                $actual >= $amount => 'tercapai',
                $expected > 0 && $actual < $expected * (1 - self::PACE_TOLERANCE) => 'tertinggal',
                default => 'aman',
            };
        }

        return match (true) {
            $actual > $amount => 'lewat',
            $percentage >= self::WARNING_PERCENTAGE && $actual > $expected * (1 + self::PACE_TOLERANCE) => 'waspada',
            default => 'aman',
        };
    }

    /** Porsi tahun yang sudah berjalan (0..1), dihitung per hari. */
    public function elapsedFraction(int $year, Carbon $asOf): float
    {
        if ($asOf->year > $year) {
            return 1.0;
        }
        if ($asOf->year < $year) {
            return 0.0;
        }

        return min(1.0, $asOf->dayOfYear / ($asOf->isLeapYear() ? 366 : 365));
    }
}
