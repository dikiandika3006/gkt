<?php

namespace App\Insights\Rules;

use App\Insights\Contracts\Insight;
use App\Insights\InsightContext;
use App\Insights\InsightResult;

/** Realisasi vs anggaran tahun berjalan (lihat BudgetService untuk aturan status). */
class OverBudgetInsight implements Insight
{
    public function evaluate(InsightContext $context): iterable
    {
        $year = $context->now->year;
        $report = $context->budgets->realization($year, $context->now);
        $rows = $report['rows'];

        if ($rows->isEmpty()) {
            yield new InsightResult(
                key: 'budget',
                severity: 'info',
                title: "Anggaran {$year} belum diisi",
                message: 'Isi anggaran per pos agar sistem bisa memberi peringatan over budget.',
                recommendation: 'Masukkan RAPB di menu Anggaran, atau salin dari tahun lalu.',
                link: '/budgets',
            );

            return;
        }

        $item = fn (array $r) => ['label' => $r['category']['name'] ?? '-', 'value' => $r['actual'], 'note' => number_format($r['percentage'], 0, ',', '.').'%'];

        $over = $rows->where('status', 'lewat')->sortByDesc('percentage');
        if ($over->isNotEmpty()) {
            yield new InsightResult(
                key: 'budget.over',
                severity: 'danger',
                title: $over->count().' pos melebihi anggaran',
                message: sprintf('%s sudah terpakai %s%% dari anggaran %d.', $over->first()['category']['name'] ?? '-', number_format($over->first()['percentage'], 0, ',', '.'), $year),
                recommendation: 'Hentikan pengeluaran di pos ini kecuali mendesak, atau ajukan revisi anggaran ke majelis.',
                link: '/budgets',
                items: $over->take(5)->map($item)->values()->all(),
            );
        }

        $risk = $rows->where('status', 'waspada')->sortByDesc('percentage');
        if ($risk->isNotEmpty()) {
            yield new InsightResult(
                key: 'budget.risk',
                severity: 'warning',
                title: $risk->count().' pos berisiko over budget',
                message: sprintf('Pemakaian lebih cepat dari jadwal: seharusnya baru sekitar %s%% di titik ini.', number_format($report['elapsed'] * 100, 0, ',', '.')),
                recommendation: 'Rem pengeluaran di pos-pos ini agar cukup sampai akhir tahun.',
                link: '/budgets',
                items: $risk->take(5)->map($item)->values()->all(),
            );
        }

        $behind = $rows->where('status', 'tertinggal')->sortBy('percentage');
        if ($behind->isNotEmpty()) {
            yield new InsightResult(
                key: 'budget.income_behind',
                severity: 'warning',
                title: $behind->count().' target penerimaan tertinggal',
                message: 'Penerimaan di bawah jadwal target tahunan.',
                recommendation: 'Evaluasi program penggalangan dana dan pastikan semua penerimaan sudah tercatat.',
                link: '/budgets',
                items: $behind->take(5)->map($item)->values()->all(),
            );
        }

        if ($over->isEmpty() && $risk->isEmpty() && $behind->isEmpty()) {
            yield new InsightResult(
                key: 'budget.ok',
                severity: 'success',
                title: "Anggaran {$year} terkendali",
                message: sprintf('Pengeluaran %s dari anggaran %s.', InsightContext::rupiah($report['totals']['expense_actual']), InsightContext::rupiah($report['totals']['expense_budget'])),
                link: '/budgets',
            );
        }
    }
}
