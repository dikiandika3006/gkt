<?php

namespace App\Models;

use App\Enums\CashAccountType;
use App\Models\Concerns\LogsActivity;
use Database\Factories\CashAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'type', 'bank_name', 'account_number', 'opening_balance', 'is_active'])]
class CashAccount extends Model
{
    /** @use HasFactory<CashAccountFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => CashAccountType::class,
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function fundRequests(): HasMany
    {
        return $this->hasMany(FundRequest::class);
    }
}
