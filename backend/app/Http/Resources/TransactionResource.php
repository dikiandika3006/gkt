<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date->toDateString(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'counterparty' => $this->counterparty,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'bank_ref' => $this->bank_ref,
            'bank_description' => $this->bank_description,
            'category_id' => $this->category_id,
            'cash_account_id' => $this->cash_account_id,
            'transfer_group' => $this->transfer_group,
            'fund_request_id' => $this->fund_request_id,
            'fund_report_item_id' => $this->fund_report_item_id,
            'recurring_expense_id' => $this->recurring_expense_id,
            'recurring_period' => $this->recurring_period,
            'is_managed' => $this->isManaged(),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'cash_account' => new CashAccountResource($this->whenLoaded('cashAccount')),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only('id', 'name')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'attachments_count' => $this->whenCounted('attachments'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
