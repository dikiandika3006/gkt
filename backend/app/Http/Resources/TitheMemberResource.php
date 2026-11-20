<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TitheMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'alias' => $this->alias,
            'name' => $this->name,
            'label' => $this->label,
            'is_active' => $this->is_active,
        ];
    }
}
