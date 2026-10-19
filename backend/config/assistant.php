<?php

use App\Assistant\Intents;
use App\Assistant\RuleBasedAnswerer;

/*
| Asisten "Tanya Keuangan" — tanya-jawab berdasarkan data aplikasi, tanpa layanan AI luar.
|
| - 'answerer': mesin penjawab. Ganti dengan implementasi lain (mis. LLM lokal di VPS)
|   yang mengimplementasikan App\Assistant\Contracts\Answerer.
| - 'intents': jenis pertanyaan yang dipahami. Untuk mengajari pertanyaan baru, buat class
|   yang mengimplementasikan App\Assistant\Contracts\Intent lalu daftarkan di sini.
|   Urutan = prioritas bila skornya sama.
*/

return [
    'answerer' => RuleBasedAnswerer::class,

    'intents' => [
        Intents\RunwayIntent::class,
        Intents\BudgetIntent::class,
        Intents\RecurringIntent::class,
        Intents\TopIntent::class,
        Intents\CompareIntent::class,
        Intents\NetIntent::class,
        Intents\RecentTransactionsIntent::class,
        Intents\BalanceIntent::class,
        Intents\TotalIntent::class,
        Intents\HelpIntent::class,
    ],

    // Contoh pertanyaan yang ditampilkan saat chat dibuka.
    'starter_questions' => [
        'Berapa saldo sekarang?',
        'Pengeluaran terbesar bulan ini apa?',
        'Bulan ini surplus atau defisit?',
        'Pengeluaran bulan ini dibanding bulan lalu',
        'Pos mana yang over budget?',
        'Biaya rutin apa yang belum dibayar?',
    ],
];
