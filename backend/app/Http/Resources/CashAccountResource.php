<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'opening_balance' => (float) $this->opening_balance,
            'is_active' => $this->is_active,
        ];
    }
}
