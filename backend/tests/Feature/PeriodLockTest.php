<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodLockTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $bank;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->seed(ChartOfAccountsSeeder::class);

        $this->bank = CashAccount::factory()->create(['opening_balance' => 1_000_000]);
        $this->category = Category::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return [
            'date' => '2026-10-05', 'type' => 'income', 'category_id' => $this->category->id,
            'cash_account_id' => $this->bank->id, 'amount' => 150_000, 'description' => 'Persembahan', ...$overrides,
        ];
    }

    public function test_locked_month_blocks_create_update_delete_and_moving_in(): void
    {
        $october = Transaction::factory()->create(['date' => '2026-10-10', 'cash_account_id' => $this->bank->id, 'category_id' => $this->category->id]);
        $november = Transaction::factory()->create(['date' => '2026-11-02', 'cash_account_id' => $this->bank->id, 'category_id' => $this->category->id]);

        $this->as(UserRole::Bendahara);
        $this->postJson('/api/period-locks', ['month' => '2026-10', 'notes' => 'Sudah diumumkan'])->assertCreated();

        $this->postJson('/api/transactions', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->putJson("/api/transactions/{$october->id}", $this->payload(['date' => '2026-10-10', 'amount' => 1]))->assertUnprocessable();
        $this->deleteJson("/api/transactions/{$october->id}")->assertUnprocessable();
        $this->putJson("/api/transactions/{$november->id}", $this->payload(['date' => '2026-10-20']))->assertUnprocessable();
        $this->postJson('/api/transfers', [
            'date' => '2026-10-01', 'from_cash_account_id' => $this->bank->id,
            'to_cash_account_id' => CashAccount::factory()->create()->id, 'amount' => 1000, 'description' => 'x',
        ])->assertUnprocessable();

        // Bulan yang belum ditutup tetap bisa.
        $this->postJson('/api/transactions', $this->payload(['date' => '2026-11-03']))->assertCreated();
        $this->assertSame(3, Transaction::count());

        $this->getJson('/api/transactions?cash_account_id='.$this->bank->id.'&start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()->assertJsonPath('data.0.locked', true);
    }

    public function test_only_admin_can_reopen_and_future_months_cannot_be_locked(): void
    {
        $this->as(UserRole::Bendahara);
        $this->postJson('/api/period-locks', ['month' => '2026-12'])->assertJsonValidationErrors('month');
        $this->postJson('/api/period-locks', ['month' => '2026-10'])->assertCreated();
        $this->postJson('/api/period-locks', ['month' => '2026-10'])->assertJsonValidationErrors('month');
        $this->deleteJson('/api/period-locks/2026-10')->assertForbidden();

        $this->as(UserRole::Viewer);
        $this->postJson('/api/period-locks', ['month' => '2026-09'])->assertForbidden();

        $this->as(UserRole::Admin);
        $this->getJson('/api/period-locks?year=2026')->assertOk()
            ->assertJsonPath('months.9.locked', true)
            ->assertJsonPath('months.11.is_future', true);
        $this->deleteJson('/api/period-locks/2026-10')->assertOk();
        $this->postJson('/api/transactions', $this->payload())->assertCreated();
    }

    public function test_reconciliation_compares_statement_with_app_balance(): void
    {
        Transaction::factory()->create(['date' => '2026-10-10', 'cash_account_id' => $this->bank->id, 'category_id' => $this->category->id, 'amount' => 200_000]);
        Transaction::factory()->create(['date' => '2026-11-02', 'cash_account_id' => $this->bank->id, 'category_id' => $this->category->id, 'amount' => 50_000]);

        $this->as(UserRole::Bendahara);
        $this->postJson('/api/bank-reconciliations', ['cash_account_id' => $this->bank->id, 'month' => '2026-10', 'statement_balance' => 1_195_000, 'notes' => 'Biaya admin belum dicatat'])->assertOk();
        // Simpan ulang = perbarui, bukan dobel.
        $this->postJson('/api/bank-reconciliations', ['cash_account_id' => $this->bank->id, 'month' => '2026-10', 'statement_balance' => 1_200_000])->assertOk();

        $row = collect($this->getJson('/api/bank-reconciliations?month=2026-10')->assertOk()->json('accounts'))->firstWhere('cash_account_id', $this->bank->id);
        $this->assertEquals(1_200_000, $row['app_balance']);
        $this->assertEquals(0, $row['difference']);

        $this->as(UserRole::Viewer);
        $this->postJson('/api/bank-reconciliations', ['cash_account_id' => $this->bank->id, 'month' => '2026-10', 'statement_balance' => 1])->assertForbidden();
    }
}
