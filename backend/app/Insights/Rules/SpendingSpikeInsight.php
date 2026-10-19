<?php

namespace App\Insights\Rules;

use App\Enums\CategoryNature;
use App\Insights\Contracts\Insight;
use App\Insights\InsightContext;
use App\Insights\InsightResult;

/**
 * Kategori yang pengeluaran bulan berjalannya SUDAH melampaui rata-rata bulanan + ambang.
 * Memakai realisasi berjalan (bukan proyeksi) agar awal bulan tidak memicu peringatan palsu.
 */
class SpendingSpikeInsight implements Insight
{
    public function evaluate(InsightContext $context): iterable
    {
        $months = (int) $context->threshold('spending_spike.months', 6);
        $percent = (float) $context->threshold('spending_spike.percent', 30);
        $minDifference = (float) $context->threshold('spending_spike.min_difference', 500_000);
        $limit = (int) $context->threshold('spending_spike.limit', 3);

        $monthStart = $context->monthStart();
        $history = $context->report->byCategory($monthStart->copy()->subMonths($months), $monthStart->copy()->subDay()->endOfDay())['expense']
            ->where('nature', CategoryNature::Operational->value)
            ->keyBy('category_id');
        $current = $context->report->byCategory($monthStart, $context->now->copy()->endOfDay())['expense']
            ->where('nature', CategoryNature::Operational->value);

        $spikes = $current
            ->map(function (array $row) use ($history, $months) {
                $average = (float) ($history[$row['category_id']]['total'] ?? 0) / $months;

                return [...$row, 'average' => $average, 'difference' => $row['total'] - $average];
            })
            ->filter(fn (array $row) => $row['average'] > 0
                && $row['total'] > $row['average'] * (1 + $percent / 100)
                && $row['difference'] >= $minDifference)
            ->sortByDesc('difference')
            ->take($limit);

        foreach ($spikes as $row) {
            $increase = round(($row['total'] / $row['average'] - 1) * 100);

            yield new InsightResult(
                key: 'spending_spike.'.$row['category_id'],
                severity: 'warning',
                title: "{$row['name']} naik {$increase}%",
                message: sprintf(
                    'Bulan ini sudah %s, padahal rata-rata %d bulan terakhir %s per bulan.',
                    InsightContext::rupiah($row['total']),
                    $months,
                    InsightContext::rupiah($row['average']),
                ),
                recommendation: "Periksa transaksi {$row['name']} bulan ini. Jika memang wajar (mis. kegiatan khusus), sesuaikan anggarannya.",
                link: '/transactions',
            );
        }
    }
}
