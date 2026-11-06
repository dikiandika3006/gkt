<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kas yang boleh dipakai role di modul Mutasi (menu Hak Akses). */
#[Fillable(['role', 'cash_account_id'])]
class RoleCashAccount extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return ['role' => UserRole::class];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }
}
