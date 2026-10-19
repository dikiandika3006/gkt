<?php

namespace App\Models;

use App\Enums\FundRequestStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/** Pengajuan dana operasional suatu kas (mis. Kas Sekretariat) untuk satu periode bulan. */
#[Fillable(['number', 'cash_account_id', 'period', 'request_date', 'notes', 'created_by'])]
class FundRequest extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FundRequest $request) {
            $request->number ??= static::generateNumber($request->period);
        });
    }

    /** Format: PD-202610-0001. */
    public static function generateNumber(string $period): string
    {
        $prefix = 'PD-'.Carbon::createFromFormat('Y-m', $period)->format('Ym').'-';
        $last = static::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FundRequestItem::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(FundReport::class);
    }

    /** Sisi masuk (ke kas penerima) dari mutasi antar kas yang ditautkan ke pengajuan ini. */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transaction::class)
            ->whereNotNull('transfer_group')
            ->where('type', TransactionType::Income)
            ->orderBy('date');
    }

    public function requestedAmount(): float
    {
        return round((float) $this->items->sum('amount'), 2);
    }

    public function transferredAmount(): float
    {
        return round((float) $this->transfers->sum('amount'), 2);
    }

    public function reportedAmount(): float
    {
        return round((float) ($this->report?->items->sum('amount') ?? 0), 2);
    }

    /** Butuh relasi items, transfers, report.items sudah di-load. */
    public function status(): FundRequestStatus
    {
        return match (true) {
            $this->report?->submitted_at !== null => FundRequestStatus::Completed,
            $this->report !== null && $this->report->items->isNotEmpty() => FundRequestStatus::PartiallyReported,
            $this->transfers->isNotEmpty() => FundRequestStatus::Transferred,
            default => FundRequestStatus::Submitted,
        };
    }
}
