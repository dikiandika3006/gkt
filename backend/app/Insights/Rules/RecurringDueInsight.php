<?php

namespace App\Insights\Rules;

use App\Insights\Contracts\Insight;
use App\Insights\InsightContext;
use App\Insights\InsightResult;

/** Biaya rutin bulan ini yang belum lunas dan kecukupan saldo rekening. */
class RecurringDueInsight implements Insight
{
    public function evaluate(InsightContext $context): iterable
    {
        $status = $context->recurring->status($context->now->format('Y-m'));
        $items = collect($status['items']);

        if ($items->isEmpty()) {
            return [];
        }

        $unpaid = $items->where('status', '!=', 'lunas');
        $remaining = $status['sufficiency']['remaining'];
        $shortfall = $status['sufficiency']['shortfall'];
        $list = $unpaid->map(fn (array $i) => ['label' => $i['name'], 'value' => $i['remaining']])->values()->all();

        if ($shortfall > 0) {
            return [new InsightResult(
                key: 'recurring_due',
                severity: 'danger',
                title: 'Saldo rekening kurang untuk biaya rutin',
                message: sprintf('Sisa biaya rutin %s, saldo rekening kurang %s.', InsightContext::rupiah($remaining), InsightContext::rupiah($shortfall)),
                recommendation: 'Lakukan mutasi dari kas lain ke rekening atau jadwalkan ulang pembayaran yang belum jatuh tempo.',
                link: '/recurring',
                items: $list,
            )];
        }

        if ($unpaid->isNotEmpty()) {
            return [new InsightResult(
                key: 'recurring_due',
                severity: 'info',
                title: $unpaid->count().' biaya rutin belum lunas',
                message: sprintf('Sisa %s untuk %s. Saldo rekening cukup.', InsightContext::rupiah($remaining), $context->monthLabel()),
                recommendation: 'Catat pembayaran lewat menu Biaya Rutin agar statusnya ter-update.',
                link: '/recurring',
                items: $list,
            )];
        }

        return [new InsightResult('recurring_due', 'success', 'Biaya rutin bulan ini lunas', 'Semua biaya rutin '.$context->monthLabel().' sudah dibayar.', null, '/recurring')];
    }
}
