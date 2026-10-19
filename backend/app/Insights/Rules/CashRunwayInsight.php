<?php

namespace App\Insights\Rules;

use App\Insights\Contracts\Insight;
use App\Insights\InsightContext;
use App\Insights\InsightResult;

/** Saldo cukup untuk berapa bulan pengeluaran operasional rata-rata. */
class CashRunwayInsight implements Insight
{
    public function evaluate(InsightContext $context): iterable
    {
        $danger = (float) $context->threshold('cash_runway.danger_months', 3);
        $warning = (float) $context->threshold('cash_runway.warning_months', 6);
        $resilience = $context->report->cashResilience();

        if ($resilience['months'] === null) {
            return [];
        }

        $months = $resilience['months'];
        $message = sprintf(
            'Saldo %s cukup untuk sekitar %s bulan pengeluaran operasional (rata-rata %s per bulan).',
            InsightContext::rupiah($resilience['balance']),
            number_format($months, 1, ',', '.'),
            InsightContext::rupiah($resilience['average_expense']),
        );

        [$severity, $title, $recommendation] = match (true) {
            $months < $danger => ['danger', 'Dana cadangan kritis', 'Prioritaskan pengeluaran wajib (tunjangan, listrik, air) dan tunda pengeluaran lain sampai pemasukan membaik.'],
            $months < $warning => ['warning', 'Dana cadangan menipis', "Usahakan saldo bertahan minimal {$warning} bulan pengeluaran; kurangi pengeluaran yang bisa ditunda."],
            default => ['success', 'Dana cadangan sehat', 'Kondisi aman. Kelebihan dana bisa dialokasikan ke program prioritas atau dana darurat.'],
        };

        return [new InsightResult('cash_runway', $severity, $title, $message, $recommendation, '/cash-accounts')];
    }
}
