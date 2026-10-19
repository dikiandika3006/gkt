<?php

namespace App\Assistant;

use App\Assistant\Contracts\Answerer;
use App\Assistant\Contracts\Intent;
use Illuminate\Contracts\Container\Container;

/** Penjawab berbasis aturan: pilih intent dengan skor tertinggi, hitung jawaban langsung dari data. */
class RuleBasedAnswerer implements Answerer
{
    public function __construct(private Container $container, private QuestionParser $parser) {}

    public function answer(string $question): AssistantAnswer
    {
        $parsed = $this->parser->parse($question);
        $best = null;
        $bestScore = 0;

        // Urutan di config = prioritas saat skor sama.
        foreach ($this->intents() as $intent) {
            $score = $intent->score($parsed);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$intent, $score];
            }
        }

        return $best?->answer($parsed) ?? $this->fallback($parsed);
    }

    /** @return Intent[] */
    private function intents(): array
    {
        return array_map(fn (string $class) => $this->container->make($class), config('assistant.intents', []));
    }

    private function fallback(ParsedQuestion $parsed): AssistantAnswer
    {
        $suggestions = $parsed->hasSubject()
            ? ["Pengeluaran {$parsed->categories->first()->name} bulan ini", "Transaksi terakhir {$parsed->categories->first()->name}", 'Bisa tanya apa saja?']
            : array_slice(config('assistant.starter_questions', []), 0, 4);

        return new AssistantAnswer(
            'Maaf, saya belum paham pertanyaan itu. Coba tanyakan soal saldo, pemasukan, pengeluaran, anggaran, atau biaya rutin — misalnya salah satu contoh di bawah.',
            suggestions: $suggestions,
            intent: 'fallback',
        );
    }
}
