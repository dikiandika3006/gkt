<?php

namespace App\Services;

use App\Enums\Module;
use App\Enums\PermissionLevel;
use App\Enums\UserRole;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Hak akses per role × modul (menu Hak Akses). Admin selalu penuh. */
class PermissionService
{
    /** @var array<string, array<string, PermissionLevel>>|null role => [module => level], cache per request */
    private ?array $matrix = null;

    public function levelFor(User $user, Module $module): PermissionLevel
    {
        if ($user->role === UserRole::Admin) {
            return PermissionLevel::Manage;
        }

        return $this->matrix()[$user->role->value][$module->value] ?? PermissionLevel::None;
    }

    public function allows(User $user, Module $module, PermissionLevel $level = PermissionLevel::View): bool
    {
        return $this->levelFor($user, $module)->covers($level);
    }

    /** @return array<string, string> module => level, untuk dikirim ke frontend. */
    public function forUser(User $user): array
    {
        return collect(Module::cases())
            ->mapWithKeys(fn (Module $m) => [$m->value => $this->levelFor($user, $m)->value])
            ->all();
    }

    /** Matriks lengkap untuk halaman Hak Akses. */
    public function matrixForEditing(): array
    {
        return [
            'roles' => array_map(fn (UserRole $r) => ['value' => $r->value, 'label' => $r->label()], Module::configurableRoles()),
            'modules' => array_map(fn (Module $m) => [
                'key' => $m->value,
                'label' => $m->label(),
                'description' => $m->description(),
                'levels' => array_map(fn (PermissionLevel $l) => $l->value, $m->levels()),
                'defaults' => collect(Module::configurableRoles())->mapWithKeys(fn (UserRole $r) => [$r->value => $m->defaultFor($r)->value])->all(),
            ], Module::cases()),
            'permissions' => collect(Module::configurableRoles())->mapWithKeys(fn (UserRole $r) => [
                $r->value => collect(Module::cases())->mapWithKeys(fn (Module $m) => [
                    $m->value => ($this->matrix()[$r->value][$m->value] ?? PermissionLevel::None)->value,
                ])->all(),
            ])->all(),
        ];
    }

    /** @param array<string, array<string, string>> $permissions role => [module => level] (sudah tervalidasi) */
    public function save(array $permissions): void
    {
        DB::transaction(function () use ($permissions) {
            foreach ($permissions as $role => $modules) {
                foreach ($modules as $module => $level) {
                    // Per baris (bukan upsert massal) agar perubahan tercatat di log aktivitas.
                    RolePermission::updateOrCreate(['role' => $role, 'module' => $module], ['level' => $level]);
                }
            }
        });

        $this->matrix = null;
    }

    private function matrix(): array
    {
        return $this->matrix ??= RolePermission::all()
            ->groupBy(fn (RolePermission $p) => $p->role->value)
            ->map(fn ($rows) => $rows->mapWithKeys(fn (RolePermission $p) => [$p->module->value => $p->level])->all())
            ->all();
    }
}
