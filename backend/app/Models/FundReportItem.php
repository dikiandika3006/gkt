<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Satu nota dalam LPJ. Selalu punya pasangan 1 transaksi pengeluaran di kas pengajuan. */
#[Fillable(['fund_report_id', 'date', 'category_id', 'description', 'qty', 'unit', 'unit_price', 'amount'])]
class FundReportItem extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(FundReport::class, 'fund_report_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
