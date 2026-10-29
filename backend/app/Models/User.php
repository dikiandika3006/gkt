<?php

namespace App\Models;

use App\Enums\Module;
use App\Enums\PermissionLevel;
use App\Enums\UserRole;
use App\Services\PermissionService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->role === ($role instanceof UserRole ? $role : UserRole::from($role))) {
                return true;
            }
        }

        return false;
    }

    /** Hak akses modul sesuai menu Hak Akses. Sengaja tidak memakai nama can() (bentrok dengan Authorizable). */
    public function hasPermission(Module|string $module, PermissionLevel|string $level = PermissionLevel::View): bool
    {
        return app(PermissionService::class)->allows(
            $this,
            $module instanceof Module ? $module : Module::from($module),
            $level instanceof PermissionLevel ? $level : PermissionLevel::from($level),
        );
    }
}
