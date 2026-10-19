<?php

namespace App\Insights\Contracts;

use App\Insights\InsightContext;
use App\Insights\InsightResult;

/**
 * Satu aturan analisis keuangan. Untuk menambah "kecerdasan" baru:
 * buat class yang mengimplementasikan interface ini, lalu daftarkan di config/insights.php.
 */
interface Insight
{
    /** @return iterable<InsightResult> kosong jika tidak ada yang perlu disampaikan */
    public function evaluate(InsightContext $context): iterable;
}
