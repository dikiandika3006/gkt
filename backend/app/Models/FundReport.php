<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** LPJ (laporan pertanggungjawaban) atas satu pengajuan dana. */
#[Fillable(['fund_request_id', 'notes', 'submitted_at', 'created_by'])]
class FundReport extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function fundRequest(): BelongsTo
    {
        return $this->belongsTo(FundRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FundReportItem::class)->orderBy('date')->orderBy('id');
    }
}
