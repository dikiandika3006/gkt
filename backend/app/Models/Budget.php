<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anggaran tahunan per kategori. Realisasi = jumlah transaksi kategori & arah yang sama pada tahun itu. */
#[Fillable(['year', 'category_id', 'type', 'amount', 'notes'])]
class Budget extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
