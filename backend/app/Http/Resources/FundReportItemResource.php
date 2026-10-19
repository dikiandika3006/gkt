<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->only('id', 'code', 'name')),
            'description' => $this->description,
            'qty' => (float) $this->qty,
            'unit' => $this->unit,
            'unit_price' => (float) $this->unit_price,
            'amount' => (float) $this->amount,
            'transaction_number' => $this->whenLoaded('transaction', fn () => $this->transaction?->number),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
