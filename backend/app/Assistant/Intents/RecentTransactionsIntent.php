<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Support\Format;

/** "Transaksi terakhir listrik?", "Pembayaran terbaru?" */
class RecentTransactionsIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        if ($q->has('transaksi terakhir', 'terbaru', 'riwayat', 'kapan', 'histori')) {
            return 5;
        }

        // "terakhir" di "3 bulan terakhir" / "setahun terakhir" adalah periode, bukan permintaan transaksi terakhir.
        return $q->has('terakhir') && $q->period->kind !== 'range' ? 5 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        $direction = $q->has('pemasukan', 'masuk', 'penerimaan', 'pengeluaran', 'keluar', 'bayar', 'dibayar') ? $q->direction() : null;

        $transactions = Transaction::query()
            ->with('category:id,name')
            ->when($q->hasSubject(), fn ($query) => $query->whereIn('category_id', $q->categories->pluck('id')))
            ->when($this->accountId($q), fn ($query, $id) => $query->where('cash_account_id', $id))
            ->when($direction, fn ($query, $d) => $query->where('type', $d))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $subject = $q->hasSubject() ? ' '.$q->subjectLabel() : '';

        if ($transactions->isEmpty()) {
            return new AssistantAnswer("Belum ada transaksi{$subject}.", link: '/transactions', intent: 'recent');
        }

        $last = $transactions->first();

        return new AssistantAnswer(
            sprintf('Transaksi%s terakhir pada %s: %s sebesar %s.', $subject, Format::date($last->date), $last->description, Format::rupiah((float) $last->amount)),
            $transactions->map(fn (Transaction $t) => [
                'label' => Format::date($t->date).' · '.$t->description,
                'value' => ($t->type === TransactionType::Income ? 1 : -1) * (float) $t->amount,
                'note' => $t->category?->name,
            ])->all(),
            [],
            '/transactions',
            'recent',
        );
    }

    public function examples(): array
    {
        return ['Transaksi terakhir listrik', 'Pemasukan terbaru apa?'];
    }
}
