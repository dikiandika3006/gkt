<?php

namespace Tests\Feature;

use App\Enums\CategoryNature;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-01 10:00:00'); // ±50% tahun berjalan
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $this->cash = CashAccount::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spend(Category $category, float $amount, string $date, TransactionType $type = TransactionType::Expense): void
    {
        Transaction::factory()->create([
            'date' => $date, 'type' => $type, 'category_id' => $category->id,
            'cash_account_id' => $this->cash->id, 'amount' => $amount,
        ]);
    }

    public function test_crud_and_duplicate_is_rejected(): void
    {
        $category = Category::factory()->expense()->create();

        $id = $this->postJson('/api/budgets', ['year' => 2026, 'category_id' => $category->id, 'type' => 'expense', 'amount' => 12_000_000])
            ->assertCreated()
            ->assertJsonPath('data.amount', 12_000_000)
            ->json('data.id');

        $this->postJson('/api/budgets', ['year' => 2026, 'category_id' => $category->id, 'type' => 'expense', 'amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->putJson("/api/budgets/{$id}", ['year' => 2026, 'category_id' => $category->id, 'type' => 'expense', 'amount' => 15_000_000])
            ->assertOk()->assertJsonPath('data.amount', 15_000_000);

        $this->deleteJson("/api/budgets/{$id}")->assertOk();
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_type_must_match_category_and_transfer_categories_are_rejected(): void
    {
        $income = Category::factory()->create();
        $transfer = Category::factory()->expense()->nature(CategoryNature::Transfer)->create();

        $this->postJson('/api/budgets', ['year' => 2026, 'category_id' => $income->id, 'type' => 'expense', 'amount' => 1000])
            ->assertJsonValidationErrors('type');
        $this->postJson('/api/budgets', ['year' => 2026, 'category_id' => $transfer->id, 'type' => 'expense', 'amount' => 1000])
            ->assertJsonValidationErrors('category_id');
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $category = Category::factory()->expense()->create();

        $this->getJson('/api/budgets?year=2026')->assertOk();
        $this->postJson('/api/budgets', ['year' => 2026, 'category_id' => $category->id, 'type' => 'expense', 'amount' => 1000])
            ->assertForbidden();
    }

    public function test_realization_statuses_follow_actuals_and_pace(): void
    {
        $listrik = Category::factory()->expense()->create(['name' => 'Listrik']);
        $atk = Category::factory()->expense()->create(['name' => 'ATK']);
        $konsumsi = Category::factory()->expense()->create(['name' => 'Konsumsi']);
        $persembahan = Category::factory()->create(['name' => 'Persembahan']);

        Budget::create(['year' => 2026, 'category_id' => $listrik->id, 'type' => 'expense', 'amount' => 10_000_000]);
        Budget::create(['year' => 2026, 'category_id' => $atk->id, 'type' => 'expense', 'amount' => 10_000_000]);
        Budget::create(['year' => 2026, 'category_id' => $konsumsi->id, 'type' => 'expense', 'amount' => 10_000_000]);
        Budget::create(['year' => 2026, 'category_id' => $persembahan->id, 'type' => 'income', 'amount' => 100_000_000]);

        $this->spend($listrik, 11_000_000, '2026-03-10');   // > 100%  -> lewat
        $this->spend($atk, 8_500_000, '2026-05-10');        // 85% saat jadwal ±50% -> waspada
        $this->spend($konsumsi, 3_000_000, '2026-02-10');   // 30% -> aman
        $this->spend($konsumsi, 9_000_000, '2025-12-10');   // tahun lalu, tidak dihitung
        $this->spend($persembahan, 20_000_000, '2026-04-01', TransactionType::Income); // 20% dari target -> tertinggal

        $rows = collect($this->getJson('/api/budgets?year=2026')->assertOk()->json('rows'))->keyBy('category.name');

        $this->assertSame('lewat', $rows['Listrik']['status']);
        $this->assertSame('waspada', $rows['ATK']['status']);
        $this->assertSame('aman', $rows['Konsumsi']['status']);
        $this->assertEquals(3_000_000, $rows['Konsumsi']['actual']);
        $this->assertSame('tertinggal', $rows['Persembahan']['status']);
    }

    public function test_copy_from_previous_year_skips_existing_rows(): void
    {
        $a = Category::factory()->expense()->create();
        $b = Category::factory()->expense()->create();
        Budget::create(['year' => 2025, 'category_id' => $a->id, 'type' => 'expense', 'amount' => 1000]);
        Budget::create(['year' => 2025, 'category_id' => $b->id, 'type' => 'expense', 'amount' => 2000]);
        Budget::create(['year' => 2026, 'category_id' => $a->id, 'type' => 'expense', 'amount' => 5000]);

        $this->postJson('/api/budgets/copy', ['from_year' => 2025, 'to_year' => 2026])
            ->assertOk()->assertJsonPath('created', 1);

        $this->assertEquals(5000, (float) Budget::where(['year' => 2026, 'category_id' => $a->id])->value('amount'));
        $this->assertEquals(2000, (float) Budget::where(['year' => 2026, 'category_id' => $b->id])->value('amount'));
    }
}
