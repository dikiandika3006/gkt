<?php

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Insights\Contracts\Insight;
use App\Insights\InsightContext;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsightTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $this->bank = CashAccount::factory()->create(['type' => CashAccountType::Bank, 'opening_balance' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(Category $category, float $amount, string $date, TransactionType $type = TransactionType::Expense): void
    {
        Transaction::factory()->create([
            'date' => $date, 'type' => $type, 'category_id' => $category->id,
            'cash_account_id' => $this->bank->id, 'amount' => $amount,
        ]);
    }

    /** @return \Illuminate\Support\Collection<string, array> keyed by insight key */
    private function insights()
    {
        return collect($this->getJson('/api/insights')->assertOk()->json('data'))->keyBy('key');
    }

    public function test_top_expense_spike_and_runway(): void
    {
        $income = Category::factory()->create();
        $listrik = Category::factory()->expense()->create(['name' => 'Listrik']);
        $atk = Category::factory()->expense()->create(['name' => 'ATK']);

        // 6 bulan normal: listrik 1 jt, ATK 2 jt per bulan, pemasukan 5 jt.
        foreach (['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'] as $m) {
            $this->tx($income, 5_000_000, "{$m}-01", TransactionType::Income);
            $this->tx($listrik, 1_000_000, "{$m}-05");
            $this->tx($atk, 2_000_000, "{$m}-05");
        }
        // Bulan ini: listrik melonjak (2,5 jt), ATK normal.
        $this->tx($listrik, 2_500_000, '2026-10-05');
        $this->tx($atk, 1_000_000, '2026-10-05');

        $insights = $this->insights();

        $this->assertArrayHasKey('spending_spike.'.$listrik->id, $insights->all());
        $this->assertArrayNotHasKey('spending_spike.'.$atk->id, $insights->all());
        $this->assertSame('Listrik', $insights['top_expense']['items'][0]['label']);
        // Saldo 30 jt - 18 jt - 3,5 jt = 8,5 jt; rata-rata pengeluaran 3 jt -> ±2,8 bulan -> kritis.
        $this->assertSame('danger', $insights['cash_runway']['severity']);
        $this->assertSame('danger', $insights->first()['severity'], 'hasil diurutkan dari yang paling kritis');
    }

    public function test_income_drop_is_flagged(): void
    {
        $income = Category::factory()->create();
        foreach (['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'] as $m) {
            $this->tx($income, 10_000_000, "{$m}-01", TransactionType::Income);
        }
        $this->tx($income, 6_000_000, '2026-09-01', TransactionType::Income);

        $this->assertSame('warning', $this->insights()['income_trend']['severity']);
    }

    public function test_budget_insights(): void
    {
        $listrik = Category::factory()->expense()->create(['name' => 'Listrik']);
        Budget::create(['year' => 2026, 'category_id' => $listrik->id, 'type' => 'expense', 'amount' => 1_000_000]);
        $this->tx($listrik, 1_500_000, '2026-02-01');

        $insights = $this->insights();
        $this->assertSame('danger', $insights['budget.over']['severity']);
        $this->assertSame('Listrik', $insights['budget.over']['items'][0]['label']);
    }

    public function test_missing_budget_and_recurring_shortfall(): void
    {
        $listrik = Category::factory()->expense()->create();
        RecurringExpense::create(['name' => 'Tunjangan', 'category_id' => $listrik->id, 'amount' => 5_000_000]);

        $insights = $this->insights();
        $this->assertSame('info', $insights['budget']['severity']);
        $this->assertSame('danger', $insights['recurring_due']['severity']);
    }

    public function test_a_failing_insight_does_not_break_the_others(): void
    {
        config(['insights.rules' => [FailingInsight::class, ...config('insights.rules')]]);

        $this->getJson('/api/insights')->assertOk()->assertJsonFragment(['key' => 'budget']);
    }
}

class FailingInsight implements Insight
{
    public function evaluate(InsightContext $context): iterable
    {
        throw new \RuntimeException('rusak');
    }
}
