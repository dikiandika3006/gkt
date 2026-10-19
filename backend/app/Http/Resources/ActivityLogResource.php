<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => $this->user?->only('id', 'name')),
            'action' => $this->action,
            'subject_type' => class_basename($this->subject_type),
            'subject_id' => $this->subject_id,
            'before' => $this->before,
            'after' => $this->after,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
