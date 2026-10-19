<?php

namespace App\Insights;

use App\Services\BudgetService;
use App\Services\FinanceReportService;
use App\Services\RecurringExpenseService;
use App\Support\Format;
use Illuminate\Support\Carbon;

/** Data & layanan yang dipakai bersama oleh semua insight. */
final class InsightContext
{
    public function __construct(
        public readonly Carbon $now,
        public readonly FinanceReportService $report,
        public readonly RecurringExpenseService $recurring,
        public readonly BudgetService $budgets,
    ) {}

    /** Ambang dari config/insights.php, mis. threshold('spending_spike.percent'). */
    public function threshold(string $key, mixed $default = null): mixed
    {
        return config("insights.thresholds.{$key}", $default);
    }

    public function monthStart(): Carbon
    {
        return $this->now->copy()->startOfMonth();
    }

    public function monthLabel(?Carbon $date = null): string
    {
        return Format::month($date ?? $this->now);
    }

    public static function rupiah(float $amount): string
    {
        return Format::rupiah($amount);
    }
}
