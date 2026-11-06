<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\RoleCashAccount;
use App\Models\RolePermission;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashAccessTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $bank;

    private CashAccount $petty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = CashAccount::factory()->create(['code' => 'KAS-001', 'name' => 'Rekening Utama']);
        $this->petty = CashAccount::factory()->create(['code' => 'KAS-002', 'name' => 'Kas Sekretariat']);
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Sekretariat diberi hak Kelola Mutasi tapi hanya Kas Sekretariat. */
    private function restrictSecretariat(): void
    {
        RolePermission::updateOrCreate(['role' => 'sekretariat', 'module' => 'transactions'], ['level' => 'manage']);
        RoleCashAccount::create(['role' => 'sekretariat', 'cash_account_id' => $this->petty->id]);
    }

    public function test_role_without_rows_sees_all_accounts(): void
    {
        Transaction::factory()->create(['cash_account_id' => $this->bank->id]);
        Transaction::factory()->create(['cash_account_id' => $this->petty->id]);
        $this->actingAsRole(UserRole::Bendahara);

        $this->getJson('/api/me')->assertJsonPath('data.cash_account_ids', null);
        $this->getJson('/api/transactions')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_restricted_role_only_reaches_its_accounts(): void
    {
        $this->restrictSecretariat();
        $bankTrx = Transaction::factory()->create(['cash_account_id' => $this->bank->id]);
        Transaction::factory()->create(['cash_account_id' => $this->petty->id]);
        $this->actingAsRole(UserRole::Sekretariat);

        $this->getJson('/api/me')->assertJsonPath('data.cash_account_ids', [$this->petty->id]);
        $this->getJson('/api/transactions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.cash_account_id', $this->petty->id);
        $this->getJson("/api/transactions?cash_account_id={$this->bank->id}")->assertForbidden();
        $this->get("/api/transactions/export?cash_account_id={$this->bank->id}")->assertForbidden();
        $this->getJson("/api/transactions/{$bankTrx->id}")->assertForbidden();
        $this->deleteJson("/api/transactions/{$bankTrx->id}")->assertForbidden();

        $category = Category::factory()->create();
        $payload = ['date' => '2026-11-01', 'type' => 'income', 'category_id' => $category->id, 'amount' => 10_000, 'description' => 'Tes'];
        $this->putJson("/api/transactions/{$bankTrx->id}", [...$payload, 'cash_account_id' => $this->petty->id])->assertForbidden();
        $this->postJson('/api/transactions', [...$payload, 'cash_account_id' => $this->bank->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_account_id');
        $this->postJson('/api/transactions', [...$payload, 'cash_account_id' => $this->petty->id])->assertCreated();

        $this->postJson('/api/transfers', [
            'date' => '2026-11-01', 'from_cash_account_id' => $this->bank->id, 'to_cash_account_id' => $this->petty->id,
            'amount' => 50_000, 'description' => 'Transfer',
        ])->assertUnprocessable()->assertJsonValidationErrors('from_cash_account_id');
    }

    public function test_transfer_legs_outside_access_are_forbidden(): void
    {
        $this->actingAsRole(UserRole::Admin);
        $group = $this->postJson('/api/transfers', [
            'date' => '2026-11-01', 'from_cash_account_id' => $this->bank->id, 'to_cash_account_id' => $this->petty->id,
            'amount' => 50_000, 'description' => 'Transfer',
        ])->assertCreated()->json('data.transfer_group');

        $this->restrictSecretariat();
        $this->actingAsRole(UserRole::Sekretariat);

        $this->getJson("/api/transfers/{$group}")->assertForbidden();
        $this->deleteJson("/api/transfers/{$group}")->assertForbidden();
    }

    public function test_admin_saves_cash_access_from_permissions_menu(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $this->putJson('/api/permissions', ['permissions' => ['viewer' => ['dashboard' => 'view']], 'cash_accounts' => ['sekretariat' => [$this->petty->id]]])
            ->assertOk()
            ->assertJsonPath('cash_accounts.sekretariat', [$this->petty->id])
            ->assertJsonPath('cash_accounts.bendahara', null);
        $this->assertTrue(ActivityLog::where('subject_type', (new RoleCashAccount)->getMorphClass())->exists());

        $this->putJson('/api/permissions', ['permissions' => ['viewer' => ['dashboard' => 'view']], 'cash_accounts' => ['sekretariat' => null]])
            ->assertOk()
            ->assertJsonPath('cash_accounts.sekretariat', null);
        $this->assertSame(0, RoleCashAccount::count());

        $this->putJson('/api/permissions', ['permissions' => ['viewer' => ['dashboard' => 'view']], 'cash_accounts' => ['sekretariat' => []]])
            ->assertUnprocessable();
        $this->putJson('/api/permissions', ['permissions' => ['viewer' => ['dashboard' => 'view']], 'cash_accounts' => ['admin' => [$this->petty->id]]])
            ->assertUnprocessable();
    }

    public function test_default_account_follows_settings_and_access(): void
    {
        $this->restrictSecretariat();
        $this->actingAsRole(UserRole::Admin);
        $this->putJson('/api/settings', [
            'mutation_cash_account_id' => $this->bank->id,
            'mutation_fallback_cash_account_id' => $this->petty->id,
        ])->assertOk()->assertJsonPath('mutation_cash_account_id', $this->bank->id);

        $this->getJson('/api/me')->assertJsonPath('data.default_cash_account_id', $this->bank->id);

        $this->actingAsRole(UserRole::Bendahara);
        $this->getJson('/api/me')->assertJsonPath('data.default_cash_account_id', $this->bank->id);

        $this->actingAsRole(UserRole::Sekretariat);
        $this->getJson('/api/me')->assertJsonPath('data.default_cash_account_id', $this->petty->id);
    }

    public function test_default_account_without_settings_is_first_accessible(): void
    {
        $this->restrictSecretariat();

        $this->actingAsRole(UserRole::Bendahara);
        $this->getJson('/api/me')->assertJsonPath('data.default_cash_account_id', $this->bank->id);

        $this->actingAsRole(UserRole::Sekretariat);
        $this->getJson('/api/me')->assertJsonPath('data.default_cash_account_id', $this->petty->id);
    }
}
