<?php

namespace App\Models;

use App\Enums\Module;
use App\Enums\PermissionLevel;
use App\Enums\UserRole;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'module', 'level'])]
class RolePermission extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'module' => Module::class,
            'level' => PermissionLevel::class,
        ];
    }
}
