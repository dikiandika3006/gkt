<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'group' => $this->group,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'nature' => $this->nature->value,
            'nature_label' => $this->nature->label(),
            'description' => $this->description,
            'is_active' => $this->is_active,
            'transactions_count' => $this->whenCounted('transactions'),
        ];
    }
}
