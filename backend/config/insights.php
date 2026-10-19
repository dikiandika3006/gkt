<?php

use App\Insights\Rules;

/*
| Insight Keuangan — mesin analisis berbasis aturan (tanpa koneksi ke layanan AI luar).
|
| Menambah "kecerdasan" baru: buat class yang mengimplementasikan App\Insights\Contracts\Insight
| (lihat contoh di app/Insights/Rules), lalu daftarkan di 'rules'. Bisa juga class dari package composer.
| Urutan di sini tidak penting; hasil diurutkan otomatis dari yang paling butuh perhatian.
*/

return [
    'rules' => [
        Rules\OverBudgetInsight::class,
        Rules\CashRunwayInsight::class,
        Rules\RecurringDueInsight::class,
        Rules\SpendingSpikeInsight::class,
        Rules\IncomeTrendInsight::class,
        Rules\TopExpenseInsight::class,
    ],

    // Ambang bisa disesuaikan tanpa mengubah kode.
    'thresholds' => [
        'top_expense' => ['limit' => 3],
        'spending_spike' => ['months' => 6, 'percent' => 30, 'min_difference' => 500_000, 'limit' => 3],
        'income_trend' => ['months' => 6, 'percent' => 20],
        'cash_runway' => ['danger_months' => 3, 'warning_months' => 6],
    ],
];
