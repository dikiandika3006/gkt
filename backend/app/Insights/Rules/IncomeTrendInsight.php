<?php

namespace App\Insights\Rules;

use App\Insights\Contracts\Insight;
use App\Insights\InsightContext;
use App\Insights\InsightResult;

/** Pemasukan operasional bulan lalu (bulan penuh) dibanding rata-rata bulan-bulan sebelumnya. */
class IncomeTrendInsight implements Insight
{
    public function evaluate(InsightContext $context): iterable
    {
        $months = (int) $context->threshold('income_trend.months', 6);
        $percent = (float) $context->threshold('income_trend.percent', 20);

        $lastStart = $context->monthStart()->subMonth();
        $lastEnd = $lastStart->copy()->endOfMonth();
        $last = $context->report->totals($lastStart, $lastEnd)['income'];
        $average = $context->report->totals($lastStart->copy()->subMonths($months), $lastStart->copy()->subDay()->endOfDay())['income'] / $months;

        if ($average <= 0) {
            return [];
        }

        $change = ($last / $average - 1) * 100;
        $label = $context->monthLabel($lastStart);

        if ($change <= -$percent) {
            return [new InsightResult(
                key: 'income_trend',
                severity: 'warning',
                title: 'Pemasukan '.$label.' turun '.round(abs($change)).'%',
                message: sprintf('Pemasukan operasional %s, di bawah rata-rata %d bulan sebelumnya (%s).', InsightContext::rupiah($last), $months, InsightContext::rupiah($average)),
                recommendation: 'Cek apakah ada persembahan/persepuluhan yang belum dicatat. Jika benar menurun, pertimbangkan menunda pengeluaran yang tidak mendesak.',
                link: '/reports',
            )];
        }

        if ($change >= $percent) {
            return [new InsightResult(
                key: 'income_trend',
                severity: 'success',
                title: 'Pemasukan '.$label.' naik '.round($change).'%',
                message: sprintf('Pemasukan operasional %s, di atas rata-rata %d bulan sebelumnya (%s).', InsightContext::rupiah($last), $months, InsightContext::rupiah($average)),
                recommendation: 'Pertimbangkan menyisihkan kelebihan untuk dana cadangan atau program prioritas.',
                link: '/reports',
            )];
        }

        return [];
    }
}
