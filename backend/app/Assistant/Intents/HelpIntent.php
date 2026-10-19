<?php

namespace App\Assistant\Intents;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;

/** "Bisa tanya apa saja?" */
class HelpIntent extends BaseIntent
{
    public function score(ParsedQuestion $q): int
    {
        return $q->has('bantuan', 'help', 'bisa tanya', 'apa saja', 'contoh pertanyaan', 'cara pakai', 'halo', 'hai') ? 3 : 0;
    }

    public function answer(ParsedQuestion $q): AssistantAnswer
    {
        return new AssistantAnswer(
            'Saya bisa menjawab pertanyaan tentang saldo, pemasukan & pengeluaran (per kategori, kelompok, atau kas), pengeluaran terbesar, perbandingan antar periode, surplus/defisit, anggaran, biaya rutin, dan transaksi terakhir. Sebutkan periode seperti "bulan lalu", "September", atau "tahun 2025".',
            suggestions: config('assistant.starter_questions', []),
            intent: 'help',
        );
    }

    public function examples(): array
    {
        return ['Bisa tanya apa saja?'];
    }
}
