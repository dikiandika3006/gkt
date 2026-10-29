<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pos persembahan khusus (mis. Diakonia, PI Misi) dengan rekening tujuannya. */
#[Fillable(['name', 'cash_account_id', 'bank_name', 'account_number', 'account_holder', 'is_active', 'sort_order'])]
class OfferingFund extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OfferingReportLine::class);
    }

    /** Teks rekening untuk ditampilkan, mis. "BRI 1234 a.n. Komisi Diakonia" atau nama kas di sistem. */
    public function accountLabel(): string
    {
        if ($this->cash_account_id) {
            $account = $this->cashAccount;

            return trim(($account?->name ?? 'Rekening').' '.trim(($account?->bank_name ?? '').' '.($account?->account_number ?? '')));
        }

        $text = trim(implode(' ', array_filter([$this->bank_name, $this->account_number])));
        if ($this->account_holder) {
            $text .= ($text !== '' ? ' ' : '').'a.n. '.$this->account_holder;
        }

        return $text !== '' ? $text : 'Rekening '.$this->name.' (belum diisi)';
    }
}
