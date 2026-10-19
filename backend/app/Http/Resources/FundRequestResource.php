<?php

namespace App\Http\Resources;

use App\Services\FundRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'period' => $this->period,
            'request_date' => $this->request_date->toDateString(),
            'notes' => $this->notes,
            'cash_account_id' => $this->cash_account_id,
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount->only('id', 'code', 'name')),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only('id', 'name')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'description' => $i->description,
                'amount' => (float) $i->amount,
            ])),
            'transfers' => $this->whenLoaded('transfers', fn () => $this->transfers->map(fn ($t) => [
                'transfer_group' => $t->transfer_group,
                'number' => $t->number,
                'date' => $t->date->toDateString(),
                'amount' => (float) $t->amount,
                'description' => $t->description,
            ])),
            'report' => $this->whenLoaded('report', fn () => $this->report ? [
                'id' => $this->report->id,
                'notes' => $this->report->notes,
                'submitted_at' => $this->report->submitted_at?->toIso8601String(),
                'items' => FundReportItemResource::collection($this->report->items),
            ] : null),
            'summary' => app(FundRequestService::class)->summary($this->resource),
        ];
    }
}
