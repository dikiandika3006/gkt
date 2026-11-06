<?php

namespace App\Services;

use App\Enums\Module;
use App\Enums\PermissionLevel;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\RoleCashAccount;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Hak akses per role × modul (menu Hak Akses). Admin selalu penuh. */
class PermissionService
{
    /** @var array<string, array<string, PermissionLevel>>|null role => [module => level], cache per request */
    private ?array $matrix = null;

    /** @var array<string, list<int>>|null cache per request */
    private ?array $cashAccess = null;

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

    /** Kas yang boleh dipakai user di modul Mutasi. @return list<int>|null null = semua kas */
    public function cashAccountIdsFor(User $user): ?array
    {
        if ($user->role === UserRole::Admin) {
            return null;
        }

        return $this->cashAccess()[$user->role->value] ?? null;
    }

    public function canUseCashAccount(User $user, int $cashAccountId): bool
    {
        $ids = $this->cashAccountIdsFor($user);

        return $ids === null || in_array($cashAccountId, $ids, true);
    }

    /** 403 bila salah satu kas di luar akses kas user. @param iterable<int|string|null> $cashAccountIds */
    public function ensureCashAccess(User $user, iterable $cashAccountIds): void
    {
        foreach ($cashAccountIds as $id) {
            abort_if($id !== null && ! $this->canUseCashAccount($user, (int) $id), 403, 'Anda tidak punya akses ke kas ini.');
        }
    }

    /**
     * Kas awal di halaman Mutasi: kas default (Setting) bila boleh → kas cadangan (Setting) bila boleh
     * → kas aktif pertama yang boleh.
     */
    public function defaultCashAccountFor(User $user): ?int
    {
        $settings = app(AppSettingService::class);

        foreach ([AppSettingService::MUTATION_CASH_ACCOUNT, AppSettingService::MUTATION_FALLBACK_CASH_ACCOUNT] as $key) {
            $id = $settings->cashAccountSetting($key);
            if ($id !== null && $this->canUseCashAccount($user, $id)) {
                return $id;
            }
        }

        $ids = $this->cashAccountIdsFor($user);

        return CashAccount::query()
            ->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('code')
            ->value('id');
    }

    /** Matriks lengkap untuk halaman Hak Akses. */
    public function matrixForEditing(): array
    {
        return [
            'cash_accounts' => collect(Module::configurableRoles())
                ->mapWithKeys(fn (UserRole $r) => [$r->value => $this->cashAccess()[$r->value] ?? null])
                ->all(),
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

    /**
     * @param  array<string, array<string, string>>  $permissions  role => [module => level] (sudah tervalidasi)
     * @param  array<string, list<int>|null>|null  $cashAccounts  role => daftar kas, null = semua kas; null di luar = tidak diubah
     */
    public function save(array $permissions, ?array $cashAccounts = null): void
    {
        DB::transaction(function () use ($permissions, $cashAccounts) {
            foreach ($permissions as $role => $modules) {
                foreach ($modules as $module => $level) {
                    // Per baris (bukan upsert massal) agar perubahan tercatat di log aktivitas.
                    RolePermission::updateOrCreate(['role' => $role, 'module' => $module], ['level' => $level]);
                }
            }

            foreach ($cashAccounts ?? [] as $role => $ids) {
                $wanted = $ids === null ? [] : array_values(array_unique(array_map('intval', $ids)));
                $current = RoleCashAccount::where('role', $role)->get();

                // Per baris agar tercatat di log aktivitas.
                $current->reject(fn (RoleCashAccount $r) => in_array($r->cash_account_id, $wanted, true))->each->delete();
                foreach (array_diff($wanted, $current->pluck('cash_account_id')->all()) as $id) {
                    RoleCashAccount::create(['role' => $role, 'cash_account_id' => $id]);
                }
            }
        });

        $this->matrix = null;
        $this->cashAccess = null;
    }

    /** @return array<string, list<int>> role => kas yang boleh (hanya role yang dibatasi) */
    private function cashAccess(): array
    {
        return $this->cashAccess ??= RoleCashAccount::query()
            ->orderBy('cash_account_id')
            ->get()
            ->groupBy(fn (RoleCashAccount $r) => $r->role->value)
            ->map(fn ($rows) => $rows->pluck('cash_account_id')->map(fn ($id) => (int) $id)->values()->all())
            ->all();
    }

    private function matrix(): array
    {
        return $this->matrix ??= RolePermission::all()
            ->groupBy(fn (RolePermission $p) => $p->role->value)
            ->map(fn ($rows) => $rows->mapWithKeys(fn (RolePermission $p) => [$p->module->value => $p->level])->all())
            ->all();
    }
}
