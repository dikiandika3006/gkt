<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Format teks untuk pesan yang dibaca manusia (insight, asisten). */
final class Format
{
    public static function rupiah(float $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.number_format(abs($amount), 0, ',', '.');
    }

    public static function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '.').'%';
    }

    public static function month(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('F Y');
    }

    public static function date(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('j M Y');
    }
}
