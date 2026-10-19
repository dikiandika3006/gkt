<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Support\Format;

/** "Bulan ini surplus atau defisit?" */
class NetIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('surplus', 'defisit', 'selisih', 'untung', 'rugi', 'minus', 'nombok', 'sisa hasil') ? 5 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $t = $this->report->totals($q->period->start, $q->period->end, $this->accountId($q));
        $status = $t['net'] >= 0 ? 'SURPLUS' : 'DEFISIT';

        return new AssistantAnswer(
            sprintf('%s%s: %s %s (pemasukan operasional %s, pengeluaran operasional %s).', ucfirst($q->period->label()), $this->accountSuffix($q), $status, Format::rupiah(abs($t['net'])), Format::rupiah($t['income']), Format::rupiah($t['expense'])),
            [
                ['label' => 'Pemasukan operasional', 'value' => $t['income']],
                ['label' => 'Pengeluaran operasional', 'value' => $t['expense']],
                ['label' => 'Selisih', 'value' => $t['net']],
            ],
            ['Pengeluaran terbesar '.$q->period->label(), 'Pengeluaran '.$q->period->label().' dibanding periode sebelumnya'],
            '/reports',
            'net',
        );
    }

    public function examples(): array
    {
        return ['Bulan ini surplus atau defisit?', 'Tahun lalu surplus berapa?'];
    }
}
