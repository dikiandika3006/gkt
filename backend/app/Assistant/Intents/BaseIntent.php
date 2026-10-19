<?php

namespace App\Assistant\Intents;

use App\Assistant\Contracts\Intent;
use App\Assistant\ParsedQuestion;
use App\Assistant\Period;
use App\Services\BudgetService;
use App\Services\FinanceReportService;
use App\Services\RecurringExpenseService;

/** Layanan & helper bersama untuk intent bawaan. */
abstract class BaseIntent implements Intent
{
    public function __construct(
        protected FinanceReportService $report,
        protected BudgetService $budgets,
        protected RecurringExpenseService $recurring,
    ) {}

    /** Satu kas spesifik yang disebut, jika ada. */
    protected function accountId(ParsedQuestion $q): ?int
    {
        return $q->accounts->count() === 1 ? $q->accounts->first()->id : null;
    }

    /** Baris rekap per kategori untuk arah & periode, opsional difilter kategori/kelompok yang disebut. */
    protected function categoryRows(ParsedQuestion $q, string $direction, ?Period $period = null)
    {
        $period ??= $q->period;
        $rows = $this->report->byCategory($period->start, $period->end, $this->accountId($q))[$direction];

        return $q->hasSubject() ? $rows->whereIn('category_id', $q->categories->pluck('id')) : $rows;
    }

    /** Total operasional (atau total kategori/kelompok yang disebut) untuk satu arah. @return array{total: float, count: int} */
    protected function total(ParsedQuestion $q, string $direction, ?Period $period = null): array
    {
        $period ??= $q->period;

        if ($q->hasSubject()) {
            $rows = $this->categoryRows($q, $direction, $period);

            return ['total' => round((float) $rows->sum('total'), 2), 'count' => (int) $rows->sum('count')];
        }

        $totals = $this->report->totals($period->start, $period->end, $this->accountId($q));

        return ['total' => $totals[$direction], 'count' => 0];
    }

    protected function accountSuffix(ParsedQuestion $q): string
    {
        return $q->accounts->count() === 1 ? ' di '.$q->accounts->first()->name : '';
    }
}
