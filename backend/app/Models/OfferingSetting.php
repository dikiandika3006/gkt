<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Pengaturan rekap persembahan berbentuk key → JSON (mis. services = daftar kebaktian). */
#[Fillable(['key', 'value'])]
class OfferingSetting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function valueOf(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->first()?->value ?? $default;
    }

    public static function putValue(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
