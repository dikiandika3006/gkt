<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Support\Format;

/** "Berapa pengeluaran listrik bulan September?", "Pemasukan bulan ini berapa?" */
class TotalIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        $direction = $q->count(['pengeluaran', 'pemasukan', 'keluar', 'masuk', 'penerimaan', 'persembahan', 'perpuluhan', 'kolekte', 'bayar', 'dibayar', 'belanja', 'habis', 'total', 'jumlah']);

        // Hanya menyebut kategori ("listrik bulan ini") tetap dianggap menanyakan total.
        return $direction > 0 ? 2 + min($direction, 2) : ($q->hasSubject() ? 1 : 0);
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $direction = $q->direction() ?? 'expense';
        $word = $direction === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $period = $q->period->label();
        $result = $this->total($q, $direction);

        if ($q->hasSubject()) {
            $subject = $q->subjectLabel();
            $text = $result['count'] > 0
                ? sprintf('%s %s pada %s%s: %s (%d transaksi).', $word, $subject, $period, $this->accountSuffix($q), Format::rupiah($result['total']), $result['count'])
                : sprintf('Tidak ada %s untuk %s pada %s%s.', mb_strtolower($word), $subject, $period, $this->accountSuffix($q));
            $items = $q->group !== null
                ? $this->categoryRows($q, $direction)->sortByDesc('total')->map(fn ($r) => ['label' => $r['name'], 'value' => $r['total']])->values()->all()
                : [];

            return new AssistantAnswer($text, $items, [
                "Transaksi terakhir {$q->categories->first()->name}",
                "{$word} {$subject} dibanding periode sebelumnya",
                "Anggaran {$q->categories->first()->name}",
            ], '/transactions', 'total');
        }

        $top = $this->categoryRows($q, $direction)
            ->where('nature', 'operational')
            ->sortByDesc('total')
            ->take(3)
            ->map(fn ($r) => ['label' => $r['name'], 'value' => $r['total']])
            ->values()
            ->all();

        return new AssistantAnswer(
            sprintf('%s operasional %s%s: %s.', $word, $period, $this->accountSuffix($q), Format::rupiah($result['total'])),
            $top,
            [
                mb_strtolower($word).' terbesar '.$q->period->label(),
                mb_strtolower($word).' '.$q->period->label().' dibanding periode sebelumnya',
                $q->period->label().' surplus atau defisit?',
            ],
            '/reports',
            'total',
        );
    }

    public function examples(): array
    {
        return ['Berapa pengeluaran bulan ini?', 'Pengeluaran listrik bulan lalu', 'Persembahan Natal tahun lalu berapa?'];
    }
}
