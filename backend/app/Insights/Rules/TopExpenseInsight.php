<?php

namespace App\Insights\Rules;

use App\Insights\Contracts\Insight;
use App\Insights\InsightContext;
use App\Insights\InsightResult;

/** Pos pengeluaran operasional terbesar bulan berjalan. */
class TopExpenseInsight implements Insight
{
    public function evaluate(InsightContext $context): iterable
    {
        $limit = (int) $context->threshold('top_expense.limit', 3);
        $ranking = $context->report->categoryRanking($context->monthStart(), $context->now->copy()->endOfDay());

        if ($ranking->isEmpty()) {
            return [];
        }

        $top = $ranking->take($limit);
        $first = $top->first();

        return [new InsightResult(
            key: 'top_expense',
            severity: 'info',
            title: 'Pengeluaran terbesar '.$context->monthLabel(),
            message: sprintf(
                '%s menjadi pos terbesar dengan %s (%s%% dari total pengeluaran operasional bulan ini).',
                $first['name'],
                InsightContext::rupiah($first['total']),
                number_format($first['percentage'], 1, ',', '.'),
            ),
            recommendation: 'Pastikan pos-pos terbesar ini memang sesuai rencana dan anggaran.',
            link: '/reports',
            items: $top->map(fn (array $row) => [
                'label' => $row['name'],
                'value' => $row['total'],
                'note' => number_format($row['percentage'], 1, ',', '.').'%',
            ])->values()->all(),
        )];
    }
}
