<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());

        $this->cash = CashAccount::factory()->create(['opening_balance' => 1_000_000]);
        $income = Category::factory()->create();
        $expense = Category::factory()->expense()->create();

        $make = fn (string $date, TransactionType $type, int $amount) => Transaction::factory()->create([
            'date' => $date,
            'type' => $type,
            'category_id' => $type === TransactionType::Income ? $income->id : $expense->id,
            'cash_account_id' => $this->cash->id,
            'amount' => $amount,
        ]);

        $make('2026-09-15', TransactionType::Income, 500_000);   // sebelum periode
        $make('2026-10-01', TransactionType::Income, 300_000);
        $make('2026-10-10', TransactionType::Expense, 200_000);
        $make('2026-10-31', TransactionType::Income, 100_000);
        $make('2026-11-01', TransactionType::Expense, 50_000);   // sesudah periode
    }

    public function test_summary_calculates_opening_and_closing_balance(): void
    {
        $this->getJson('/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()
            ->assertJson([
                'opening_balance' => 1_500_000,
                'income' => 400_000,
                'expense' => 200_000,
                'net' => 200_000,
                'closing_balance' => 1_700_000,
            ])
            ->assertJsonCount(1, 'categories.income')
            ->assertJsonCount(1, 'categories.expense');
    }

    public function test_cash_flow_has_running_balance(): void
    {
        $response = $this->getJson('/api/reports/cash-flow?start_date=2026-10-01&end_date=2026-10-31&cash_account_id='.$this->cash->id)
            ->assertOk()
            ->assertJsonCount(3, 'rows');

        $this->assertEquals([1_800_000, 1_600_000, 1_700_000], array_column($response->json('rows'), 'balance'));
        $this->assertEquals(1_700_000, $response->json('closing_balance'));
    }

    public function test_dashboard_returns_summary(): void
    {
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['month' => ['income', 'expense', 'net'], 'total_balance', 'balances', 'trend', 'recent_transactions'])
            ->assertJsonCount(12, 'trend')
            ->assertJsonPath('total_balance', 1_650_000);
    }
}
