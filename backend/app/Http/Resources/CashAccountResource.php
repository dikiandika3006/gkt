<?php

namespace App\Http\Resources;

use App\Models\CashAccountPocket;
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
            'current_balance' => $this->whenHas('current_balance', fn ($value) => (float) $value),
            'has_pockets' => (bool) $this->has_pockets,
            'pockets' => $this->whenLoaded('pockets', fn () => $this->pockets->map(fn (CashAccountPocket $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'opening_balance' => (float) $p->opening_balance,
                'current_balance' => $p->hasAttribute('current_balance') ? (float) $p->current_balance : null,
                'is_active' => $p->is_active,
            ])->values()),
            'is_active' => $this->is_active,
        ];
    }
}
