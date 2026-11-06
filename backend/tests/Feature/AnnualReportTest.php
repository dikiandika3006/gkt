<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\OfferingReport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnualReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function offering(string $date, array $lines): void
    {
        $report = OfferingReport::create(['service_date' => $date, 'week_of_month' => 1, 'period_start' => $date, 'period_end' => $date]);
        foreach ($lines as $i => [$section, $name, $amount]) {
            $report->lines()->create(['section' => $section, 'label' => $section, 'name' => $name, 'amount' => $amount, 'sort_order' => $i]);
        }
    }

    public function test_year_comparison_and_annual_report(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $cash = CashAccount::factory()->create(['opening_balance' => 0]);
        $income = Category::factory()->create(['group' => 'Persembahan']);
        $make = fn (string $date, int $amount) => Transaction::factory()->create(['date' => $date, 'type' => TransactionType::Income, 'category_id' => $income->id, 'cash_account_id' => $cash->id, 'amount' => $amount]);
        $make('2025-03-10', 100_000);
        $make('2026-03-12', 150_000);

        $this->getJson('/api/reports/comparison?year=2026')
            ->assertOk()
            ->assertJsonPath('months.2.income', 150_000)
            ->assertJsonPath('months.2.prev_income', 100_000)
            ->assertJsonPath('groups.income.0.group', 'Persembahan')
            ->assertJsonPath('totals.prev_income', 100_000);

        $this->getJson('/api/reports/annual?year=2026')
            ->assertOk()
            ->assertJsonPath('summary.opening_balance', 100_000)
            ->assertJsonPath('summary.closing_balance', 250_000)
            ->assertJsonPath('as_of', '2026-11-15')
            ->assertJsonPath('fund_position.free_funds', 250_000);

        foreach (['comparison', 'annual', 'offering-trend'] as $name) {
            $this->get("/api/reports/{$name}/export?year=2026")->assertOk();
        }
    }

    public function test_offering_trend_and_donor_recap_with_permission(): void
    {
        $this->offering('2026-10-04', [['ibadah', null, 1_000_000], ['perpuluhan', 'Bpk Andi', 500_000], ['syukur', 'bpk  andi', 100_000], ['pengeluaran', null, 50_000]]);
        $this->offering('2026-10-11', [['ibadah', null, 800_000], ['perpuluhan', 'Ibu Sari', 300_000]]);

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $this->getJson('/api/reports/offering-trend?start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()
            ->assertJsonCount(2, 'weeks')
            ->assertJsonPath('weeks.0.total', 1_600_000)
            ->assertJsonPath('totals.total', 2_700_000)
            ->assertJsonPath('average', 1_350_000);

        // Data pribadi: default hanya admin.
        $this->getJson('/api/reports/donors?year=2026')->assertForbidden();
        $this->get('/api/reports/donors/export?year=2026')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/api/reports/donors?year=2026')
            ->assertOk()
            ->assertJsonPath('totals.donors', 2)
            ->assertJsonPath('rows.0.name', 'Bpk Andi')
            ->assertJsonPath('rows.0.perpuluhan', 500_000)
            ->assertJsonPath('rows.0.syukur', 100_000)
            ->assertJsonPath('rows.0.months.10', 600_000);
        $this->get('/api/reports/donors/export?year=2026')->assertOk();
    }
}
