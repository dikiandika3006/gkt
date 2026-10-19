<?php

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_tracks_installments_and_sufficiency(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));

        $bank = CashAccount::factory()->create(['type' => CashAccountType::Bank, 'opening_balance' => 10_000_000]);
        $category = Category::factory()->expense()->create();

        $tunjangan = $this->postJson('/api/recurring-expenses', [
            'name' => 'Tunjangan HT', 'category_id' => $category->id, 'amount' => 7_000_000,
        ])->assertCreated()->json('data.id');
        $listrik = RecurringExpense::create(['name' => 'Listrik', 'category_id' => $category->id, 'amount' => 2_000_000]);

        // Dibayar dua kali (dicicil) di tanggal berbeda.
        foreach ([['2026-10-01', 4_000_000], ['2026-10-15', 3_000_000]] as [$date, $amount]) {
            $this->postJson('/api/transactions', [
                'date' => $date, 'type' => 'expense', 'category_id' => $category->id, 'cash_account_id' => $bank->id,
                'amount' => $amount, 'description' => 'Tunjangan', 'recurring_expense_id' => $tunjangan, 'recurring_period' => '2026-10',
            ])->assertCreated();
        }

        $status = $this->getJson('/api/recurring-expenses/status?period=2026-10')->assertOk()->json();
        $items = collect($status['items'])->keyBy('id');

        $this->assertEquals('lunas', $items[$tunjangan]['status']);
        $this->assertCount(2, $items[$tunjangan]['payments']);
        $this->assertEquals('belum', $items[$listrik->id]['status']);
        $this->assertEquals(2_000_000, $status['totals']['remaining']);
        $this->assertEquals(3_000_000, $status['sufficiency']['bank_balance']);
        $this->assertEquals(0, $status['sufficiency']['shortfall']);

        // Periode lain belum ada pembayaran.
        $this->getJson('/api/recurring-expenses/status?period=2026-11')->assertJsonPath('totals.paid', 0);
    }

    public function test_recurring_link_only_for_expense_and_needs_period(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $category = Category::factory()->both()->create();
        $item = RecurringExpense::create(['name' => 'X', 'category_id' => $category->id, 'amount' => 1]);
        $base = ['date' => '2026-10-01', 'category_id' => $category->id, 'cash_account_id' => CashAccount::factory()->create()->id, 'amount' => 1, 'description' => 'x'];

        $this->postJson('/api/transactions', [...$base, 'type' => 'expense', 'recurring_expense_id' => $item->id])
            ->assertJsonValidationErrors('recurring_period');
        $this->postJson('/api/transactions', [...$base, 'type' => 'income', 'recurring_expense_id' => $item->id, 'recurring_period' => '2026-10'])
            ->assertJsonValidationErrors('recurring_expense_id');
    }
}
