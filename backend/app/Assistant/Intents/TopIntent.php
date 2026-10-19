<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Assistant\Period;
use App\Models\Transaction;
use App\Support\Format;
use Illuminate\Support\Carbon;

/** "Pengeluaran terbesar bulan lalu?", "Pemasukan terbanyak tahun ini?" */
class TopIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('terbesar', 'paling besar', 'tertinggi', 'terbanyak', 'terboros', 'paling banyak', 'ranking', 'peringkat') ? 5 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $direction = $q->direction() ?? 'expense';
        $word = $direction === 'income' ? 'pemasukan' : 'pengeluaran';

        // Satu kategori/kelompok disebut: "terbesar" = bulan & transaksi terbesar pos itu.
        if ($q->hasSubject()) {
            return $this->peakOfSubject($q, $direction, $word);
        }

        $rows = $this->categoryRows($q, $direction)->where('nature', 'operational')->sortByDesc('total')->values();
        $grand = (float) $rows->sum('total');

        if ($rows->isEmpty()) {
            return new AssistantAnswer("Belum ada {$word} operasional pada {$q->period->label()}.", intent: 'top');
        }

        $first = $rows->first();

        return new AssistantAnswer(
            sprintf(
                '%s terbesar %s: %s sebesar %s (%s dari total %s).',
                ucfirst($word),
                $q->period->label(),
                $first['name'],
                Format::rupiah($first['total']),
                Format::percent($grand > 0 ? $first['total'] / $grand * 100 : 0),
                Format::rupiah($grand),
            ),
            $rows->take(5)->map(fn ($r) => [
                'label' => $r['name'],
                'value' => $r['total'],
                'note' => Format::percent($grand > 0 ? $r['total'] / $grand * 100 : 0),
            ])->all(),
            ["Pengeluaran {$first['name']} {$q->period->label()} dibanding periode sebelumnya", "Transaksi terakhir {$first['name']}"],
            '/reports',
            'top',
        );
    }

    private function peakOfSubject(ParsedQuestion $q, string $direction, string $word): AssistantAnswer
    {
        // Rentang satu bulan terlalu sempit untuk mencari "bulan terbesar": pakai 12 bulan terakhir.
        $period = $q->period->kind === 'month' || $q->period->kind === 'day' || $q->period->kind === 'week'
            ? Period::lastMonths(now(), 12)
            : $q->period;
        $subject = $q->subjectLabel();

        $transactions = Transaction::query()
            ->whereIn('category_id', $q->categories->pluck('id'))
            ->where('type', $direction)
            ->whereDate('date', '>=', $period->start)
            ->whereDate('date', '<=', $period->end)
            ->when($this->accountId($q), fn ($query, $id) => $query->where('cash_account_id', $id))
            ->get(['id', 'date', 'description', 'amount']);

        if ($transactions->isEmpty()) {
            return new AssistantAnswer("Tidak ada {$word} {$subject} dalam {$period->label()}.", link: '/transactions', intent: 'top');
        }

        $byMonth = $transactions
            ->groupBy(fn (Transaction $t) => $t->date->format('Y-m'))
            ->map(fn ($group, $month) => ['month' => $month, 'total' => round((float) $group->sum('amount'), 2), 'count' => $group->count()])
            ->sortByDesc('total')
            ->values();
        $peak = $byMonth->first();
        $biggest = $transactions->sortByDesc(fn (Transaction $t) => (float) $t->amount)->first();
        $average = (float) $transactions->sum('amount') / max(1, $period->months());
        $monthLabel = fn (string $ym) => Format::month(Carbon::createFromFormat('Y-m-d', $ym.'-01'));

        return new AssistantAnswer(
            sprintf(
                '%s %s terbesar dalam %s: %s sebesar %s. Transaksi tunggal terbesar: %s – %s %s. Rata-rata %s per bulan.',
                ucfirst($word),
                $subject,
                $period->label(),
                $monthLabel($peak['month']),
                Format::rupiah($peak['total']),
                Format::date($biggest->date),
                $biggest->description,
                Format::rupiah((float) $biggest->amount),
                Format::rupiah($average),
            ),
            $byMonth->take(5)->map(fn ($m) => [
                'label' => $monthLabel($m['month']),
                'value' => $m['total'],
                'note' => $m['count'].' transaksi',
            ])->all(),
            ["Transaksi terakhir {$q->categories->first()->name}", "Anggaran {$q->categories->first()->name}"],
            '/transactions',
            'top',
        );
    }

    public function examples(): array
    {
        return ['Pengeluaran terbesar bulan ini apa?', 'Pemasukan terbesar tahun ini', 'Pengeluaran listrik terbesar setahun'];
    }
}
