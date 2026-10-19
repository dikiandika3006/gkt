<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Support\Format;

/** "Pengeluaran bulan ini dibanding bulan lalu naik?" */
class CompareIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('dibanding', 'dibandingkan', 'banding', 'bandingkan', 'perbandingan', 'naik', 'turun', 'vs', 'periode sebelumnya') ? 5 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $direction = $q->direction() ?? 'expense';
        $word = $direction === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $subject = $q->hasSubject() ? ' '.$q->subjectLabel() : ' operasional';
        $previous = $q->period->previous();

        $now = $this->total($q, $direction)['total'];
        $before = $this->total($q, $direction, $previous)['total'];

        $change = match (true) {
            $before > 0 => sprintf('%s %s', $now >= $before ? 'naik' : 'turun', Format::percent(abs($now / $before - 1) * 100)),
            $now > 0 => 'sebelumnya nol',
            default => 'sama-sama nol',
        };

        return new AssistantAnswer(
            sprintf('%s%s %s: %s, %s dibanding %s (%s).', $word, $subject, $q->period->label(), Format::rupiah($now), $change, $previous->label(), Format::rupiah($before)),
            [
                ['label' => ucfirst($q->period->label()), 'value' => $now],
                ['label' => ucfirst($previous->label()), 'value' => $before],
                ['label' => 'Selisih', 'value' => round($now - $before, 2)],
            ],
            [mb_strtolower($word).' terbesar '.$q->period->label()],
            '/reports',
            'compare',
        );
    }

    public function examples(): array
    {
        return ['Pengeluaran bulan ini dibanding bulan lalu', 'Persembahan tahun ini naik atau turun?'];
    }
}
