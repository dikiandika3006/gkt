<?php

namespace App\Models;

use App\Enums\OfferingSection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offering_report_id', 'section', 'offering_fund_id', 'label', 'name', 'amount', 'destination_cash_account_id', 'destination_note', 'sort_order'])]
class OfferingReportLine extends Model
{
    protected function casts(): array
    {
        return [
            'section' => OfferingSection::class,
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(OfferingReport::class, 'offering_report_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(OfferingFund::class, 'offering_fund_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'destination_cash_account_id');
    }
}
