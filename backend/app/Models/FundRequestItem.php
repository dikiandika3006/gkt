<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fund_request_id', 'description', 'amount'])]
class FundRequestItem extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function fundRequest(): BelongsTo
    {
        return $this->belongsTo(FundRequest::class);
    }
}
