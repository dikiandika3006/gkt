<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rekap persembahan mingguan untuk warta. Tidak membuat transaksi; dicocokkan dengan mutasi masuk. */
#[Fillable(['service_date', 'week_of_month', 'period_start', 'period_end', 'notes', 'created_by'])]
class OfferingReport extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'week_of_month' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OfferingReportLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Mutasi masuk yang ditautkan sebagai setoran rekap ini. */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'offering_report_transactions')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
