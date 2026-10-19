<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Support\Format;

/** "Saldo cukup untuk berapa bulan?" */
class RunwayIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('cadangan', 'ketahanan', 'bertahan', 'cukup') ? 6 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $r = $this->report->cashResilience();

        if ($r['months'] === null) {
            return new AssistantAnswer('Belum ada data pengeluaran 6 bulan terakhir untuk menghitung ketahanan kas.', intent: 'runway');
        }

        return new AssistantAnswer(
            sprintf(
                'Dengan saldo %s dan rata-rata pengeluaran operasional %s per bulan (6 bulan terakhir), dana cukup untuk sekitar %s bulan.',
                Format::rupiah($r['balance']),
                Format::rupiah($r['average_expense']),
                number_format($r['months'], 1, ',', '.'),
            ),
            suggestions: ['Pengeluaran terbesar bulan ini', 'Biaya rutin apa yang belum dibayar?'],
            link: '/',
            intent: 'runway',
        );
    }

    public function examples(): array
    {
        return ['Saldo cukup untuk berapa bulan?'];
    }
}
