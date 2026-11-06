<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sub-rekening dana khusus (pocket) di dalam rekening ber-pocket. */
#[Fillable(['cash_account_id', 'name', 'opening_balance', 'is_active', 'sort_order'])]
class CashAccountPocket extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'pocket_id');
    }
}
