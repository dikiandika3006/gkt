<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hari Minggu ke-N dalam bulan → pos persembahan khusus. */
#[Fillable(['week', 'offering_fund_id'])]
class OfferingWeekSchedule extends Model
{
    protected function casts(): array
    {
        return ['week' => 'integer'];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(OfferingFund::class, 'offering_fund_id');
    }
}
