<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Pengaturan umum aplikasi (menu Setting) berbentuk key → JSON. Perubahan tercatat di Log Aktivitas. */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return ['value' => 'json'];
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
