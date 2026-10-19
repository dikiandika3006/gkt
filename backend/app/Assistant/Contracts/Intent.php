<?php

namespace App\Assistant\Contracts;

use App\Assistant\AssistantAnswer;
use App\Assistant\ParsedQuestion;

/**
 * Satu jenis pertanyaan yang dipahami asisten. Untuk mengajari pertanyaan baru:
 * buat class yang mengimplementasikan interface ini, lalu daftarkan di config/assistant.php.
 */
interface Intent
{
    /** Seberapa cocok pertanyaan ini dengan intent (0 = tidak cocok). Skor tertinggi yang menjawab. */
    public function score(ParsedQuestion $question): int;

    public function answer(ParsedQuestion $question): AssistantAnswer;

    /** Contoh pertanyaan untuk ditawarkan ke pengguna. @return string[] */
    public function examples(): array;
}
