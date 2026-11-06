<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\PeriodLockService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bulan yang sudah ditutup buku (format Y-m). */
#[Fillable(['month', 'notes', 'locked_by'])]
class PeriodLock extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        static::saved(fn () => app(PeriodLockService::class)->flush());
        static::deleted(fn () => app(PeriodLockService::class)->flush());
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
