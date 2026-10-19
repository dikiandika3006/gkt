<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Anggaran biaya rutin bulanan. Pembayaran nyata = transaksi yang ditautkan + periode. */
#[Fillable(['name', 'category_id', 'counterparty', 'bank_info', 'amount', 'description', 'is_active', 'sort_order'])]
class RecurringExpense extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
