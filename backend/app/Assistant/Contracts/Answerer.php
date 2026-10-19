<?php

namespace App\Assistant\Contracts;

use App\Assistant\AssistantAnswer;

/**
 * Mesin penjawab asisten. Driver bawaan: berbasis aturan (App\Assistant\RuleBasedAnswerer).
 * Untuk beralih ke LLM (mis. Ollama di VPS) cukup buat implementasi baru & ganti di config/assistant.php.
 */
interface Answerer
{
    public function answer(string $question): AssistantAnswer;
}
