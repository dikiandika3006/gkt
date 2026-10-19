<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Support\Format;

/** "Biaya rutin apa yang belum dibayar?" */
class RecurringIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('rutin', 'tagihan', 'belum dibayar', 'belum bayar', 'belum lunas', 'jatuh tempo') ? 5 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $period = $q->period->kind === 'month' ? $q->period->start : now();
        $status = $this->recurring->status($period->format('Y-m'));
        $items = collect($status['items']);
        $label = Format::month($period);

        if ($items->isEmpty()) {
            return new AssistantAnswer('Belum ada daftar biaya rutin. Tambahkan di menu Biaya Rutin.', link: '/recurring', intent: 'recurring');
        }

        $unpaid = $items->where('status', '!=', 'lunas');
        if ($unpaid->isEmpty()) {
            return new AssistantAnswer("Semua biaya rutin {$label} sudah lunas.", link: '/recurring', intent: 'recurring');
        }

        $shortfall = $status['sufficiency']['shortfall'];

        return new AssistantAnswer(
            sprintf(
                '%d biaya rutin %s belum lunas, total sisa %s. %s',
                $unpaid->count(),
                $label,
                Format::rupiah($status['sufficiency']['remaining']),
                $shortfall > 0 ? 'Saldo rekening KURANG '.Format::rupiah($shortfall).'.' : 'Saldo rekening cukup.',
            ),
            $unpaid->map(fn ($i) => ['label' => $i['name'], 'value' => $i['remaining'], 'note' => $i['status']])->values()->all(),
            ['Berapa saldo rekening?'],
            '/recurring',
            'recurring',
        );
    }

    public function examples(): array
    {
        return ['Biaya rutin apa yang belum dibayar?'];
    }
}
