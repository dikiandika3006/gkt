<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\CashAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_only_admin_can_manage_settings(): void
    {
        $this->actingAsRole(UserRole::Bendahara);

        $this->getJson('/api/settings')->assertForbidden();
        $this->putJson('/api/settings', ['dashboard_cash_account_id' => null])->assertForbidden();
    }

    public function test_default_shows_all_accounts(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $this->getJson('/api/settings')->assertOk()->assertJsonPath('dashboard_cash_account_id', null);
    }

    public function test_dashboard_balance_cards_follow_selected_account(): void
    {
        $bank = CashAccount::factory()->create(['name' => 'Rekening Gereja', 'opening_balance' => 1_000_000]);
        $petty = CashAccount::factory()->create(['opening_balance' => 200_000]);
        Transaction::factory()->create(['cash_account_id' => $bank->id, 'amount' => 500_000, 'date' => now()->startOfMonth()->subDays(3)->toDateString()]);
        Transaction::factory()->create(['cash_account_id' => $bank->id, 'amount' => 300_000, 'date' => now()->toDateString()]);
        Transaction::factory()->create(['cash_account_id' => $petty->id, 'amount' => 100_000, 'date' => now()->toDateString()]);

        $this->actingAsRole(UserRole::Admin);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_balance', 2_100_000)
            ->assertJsonPath('balance_scope.cash_account_id', null)
            ->assertJsonCount(2, 'balances');

        $this->putJson('/api/settings', ['dashboard_cash_account_id' => $bank->id])
            ->assertOk()
            ->assertJsonPath('dashboard_cash_account_id', $bank->id);

        $data = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertEquals(1_800_000, $data['total_balance']);
        $this->assertEquals(1_500_000, $data['balance_opening']);
        $this->assertSame(['cash_account_id' => $bank->id, 'name' => 'Rekening Gereja'], $data['balance_scope']);
        $this->assertCount(1, $data['balances']);
        $this->assertSame($bank->id, $data['balances'][0]['id']);
        // Angka di luar kartu saldo tetap gabungan semua kas.
        $this->assertEquals(2_100_000, $data['resilience']['balance']);

        $this->putJson('/api/settings', ['dashboard_cash_account_id' => null])->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('total_balance', 2_100_000)->assertJsonCount(2, 'balances');
    }

    public function test_rejects_unknown_account_and_falls_back_when_deleted(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $this->putJson('/api/settings', ['dashboard_cash_account_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dashboard_cash_account_id');

        $account = CashAccount::factory()->create();
        $this->putJson('/api/settings', ['dashboard_cash_account_id' => $account->id])->assertOk();
        $account->delete();

        $this->getJson('/api/settings')->assertJsonPath('dashboard_cash_account_id', null);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('balance_scope.cash_account_id', null);
    }

    public function test_setting_changes_are_logged(): void
    {
        $account = CashAccount::factory()->create();
        $this->actingAsRole(UserRole::Admin);

        $this->putJson('/api/settings', ['dashboard_cash_account_id' => $account->id])->assertOk();
        $this->putJson('/api/settings', ['dashboard_cash_account_id' => null])->assertOk();

        $logs = ActivityLog::where('subject_type', (new AppSetting)->getMorphClass())->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $logs->pluck('action')->all());
    }
}
