<?php

namespace App\Http\Resources;

use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_active' => $this->is_active,
            // Hak akses per modul (menu Hak Akses), dipakai frontend untuk menu & tombol.
            'permissions' => app(PermissionService::class)->forUser($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
