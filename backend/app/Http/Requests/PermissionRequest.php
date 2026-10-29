<?php

namespace App\Http\Requests;

use App\Enums\Module;
use App\Enums\PermissionLevel;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Simpan matriks hak akses: permissions[role][module] = level. Admin tidak bisa diatur. */
class PermissionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['array'],
            'permissions.*.*' => ['required', Rule::enum(PermissionLevel::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $roles = array_map(fn (UserRole $r) => $r->value, Module::configurableRoles());

                foreach ((array) $this->input('permissions', []) as $role => $modules) {
                    if (! in_array($role, $roles, true)) {
                        $validator->errors()->add("permissions.{$role}", 'Hak akses role ini tidak bisa diubah.');

                        continue;
                    }

                    foreach ((array) $modules as $key => $level) {
                        $module = Module::tryFrom((string) $key);
                        if (! $module) {
                            $validator->errors()->add("permissions.{$role}.{$key}", 'Modul tidak dikenal.');
                        } elseif (($lvl = PermissionLevel::tryFrom((string) $level)) && ! in_array($lvl, $module->levels(), true)) {
                            $validator->errors()->add("permissions.{$role}.{$key}", "{$module->label()} tidak punya level {$lvl->label()}.");
                        }
                    }
                }
            },
        ];
    }
}
