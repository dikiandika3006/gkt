<?php

namespace App\Http\Resources;

use App\Services\OfferingReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferingReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_date' => $this->service_date->toDateString(),
            'week_of_month' => $this->week_of_month,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'notes' => $this->notes,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only('id', 'name')),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'section' => $l->section->value,
                'offering_fund_id' => $l->offering_fund_id,
                'fund' => $l->relationLoaded('fund') ? $l->fund?->only('id', 'name') : null,
                'label' => $l->label,
                'name' => $l->name,
                'amount' => (float) $l->amount,
                'destination_cash_account_id' => $l->destination_cash_account_id,
                'destination' => $l->relationLoaded('destination') ? $l->destination?->only('id', 'code', 'name', 'bank_name', 'account_number') : null,
                'destination_note' => $l->destination_note,
            ])),
            'transactions' => $this->whenLoaded('transactions', fn () => $this->transactions->map(fn ($t) => [
                'id' => $t->id,
                'number' => $t->number,
                'date' => $t->date->toDateString(),
                'description' => $t->description,
                'counterparty' => $t->counterparty,
                'category' => $t->relationLoaded('category') ? $t->category?->name : null,
                'amount' => (float) $t->amount,
            ])),
            'summary' => app(OfferingReportService::class)->summary($this->resource),
        ];
    }
}
