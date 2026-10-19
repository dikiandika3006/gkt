<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\LogsActivity;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable([
    'number', 'date', 'type', 'category_id', 'cash_account_id', 'amount', 'description', 'counterparty', 'reference', 'notes',
    'bank_ref', 'bank_description', 'transfer_group', 'fund_request_id', 'fund_report_item_id', 'recurring_expense_id', 'recurring_period', 'created_by',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            $transaction->number ??= static::generateNumber($transaction->type, $transaction->date);
        });
    }

    /** Format: KM-202610-0001 (nomor urut per jenis per bulan). */
    public static function generateNumber(TransactionType|string $type, $date): string
    {
        $type = $type instanceof TransactionType ? $type : TransactionType::from($type);
        $prefix = $type->numberPrefix().'-'.Carbon::parse($date)->format('Ym').'-';

        $last = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Panggil di dalam DB::transaction sebelum create agar nomor tidak bentrok saat input bersamaan. */
    public static function lockNumbering(): void
    {
        static::withTrashed()->lockForUpdate()->latest('id')->first();
    }

    /** Transaksi ini bagian dari mutasi antar kas atau LPJ — dikelola lewat modulnya, bukan form transaksi biasa. */
    public function isManaged(): bool
    {
        return $this->transfer_group !== null || $this->fund_report_item_id !== null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fundRequest(): BelongsTo
    {
        return $this->belongsTo(FundRequest::class);
    }

    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
