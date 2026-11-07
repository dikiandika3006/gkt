<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saldo menurut rekening koran / hitung fisik suatu kas pada akhir bulan. */
#[Fillable(['cash_account_id', 'month', 'statement_balance', 'notes', 'reconciled_by'])]
class BankReconciliation extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return ['statement_balance' => 'decimal:2'];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
