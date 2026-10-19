<?php

namespace Tests\Feature;

use App\Enums\CategoryNature;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferAndCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
    }

    public function test_both_direction_category_accepts_income_and_expense(): void
    {
        $category = Category::factory()->both()->create();
        $account = CashAccount::factory()->create();

        foreach (['income', 'expense'] as $type) {
            $this->postJson('/api/transactions', [
                'date' => '2026-10-05', 'type' => $type, 'category_id' => $category->id,
                'cash_account_id' => $account->id, 'amount' => 1000, 'description' => 'Kas komisi',
            ])->assertCreated();
        }

        // Filter jenis ikut menampilkan kategori dua arah.
        $this->getJson('/api/categories?type=expense')->assertJsonFragment(['id' => $category->id]);
    }

    public function test_transfer_creates_paired_transactions_and_keeps_total_balance(): void
    {
        $bank = CashAccount::factory()->create(['opening_balance' => 1_000_000]);
        $petty = CashAccount::factory()->create(['opening_balance' => 0]);

        $group = $this->postJson('/api/transfers', [
            'date' => '2026-10-01', 'from_cash_account_id' => $bank->id, 'to_cash_account_id' => $petty->id,
            'amount' => 300_000, 'description' => 'Dana sekretariat Oktober',
        ])->assertCreated()->assertJsonCount(2, 'data.legs')->json('data.transfer_group');

        $this->getJson('/api/dashboard')->assertJsonPath('total_balance', 1_000_000);

        $summary = $this->getJson('/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31')->json();
        $this->assertEquals(0, $summary['income']);
        $this->assertEquals(0, $summary['expense']);
        $this->assertCount(0, $summary['categories']['expense']); // mutasi tidak muncul di rekap gereja

        $perAccount = $this->getJson("/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31&cash_account_id={$petty->id}")->json();
        $this->assertEquals(300_000, $perAccount['transfer_in']);
        $this->assertEquals(300_000, $perAccount['closing_balance']);

        // Transaksi mutasi tidak bisa diubah/dihapus dari menu transaksi biasa.
        $leg = Transaction::where('transfer_group', $group)->first();
        $this->deleteJson("/api/transactions/{$leg->id}")->assertUnprocessable();

        $this->putJson("/api/transfers/{$group}", ['date' => '2026-10-02', 'amount' => 250_000, 'description' => 'Revisi'])->assertOk();
        $this->assertEquals(2, Transaction::where('transfer_group', $group)->where('amount', 250_000)->count());

        $this->deleteJson("/api/transfers/{$group}")->assertOk();
        $this->assertEquals(0, Transaction::where('transfer_group', $group)->count());
    }

    public function test_transfer_requires_different_accounts(): void
    {
        $bank = CashAccount::factory()->create();

        $this->postJson('/api/transfers', [
            'date' => '2026-10-01', 'from_cash_account_id' => $bank->id, 'to_cash_account_id' => $bank->id,
            'amount' => 1000, 'description' => 'x',
        ])->assertJsonValidationErrors('to_cash_account_id');
    }

    public function test_operational_totals_exclude_titipan(): void
    {
        $account = CashAccount::factory()->create();
        $titipan = Category::factory()->both()->nature(CategoryNature::Titipan)->create();
        $syukur = Category::factory()->create();

        Transaction::factory()->create(['date' => '2026-10-03', 'category_id' => $titipan->id, 'cash_account_id' => $account->id, 'amount' => 50_000_000]);
        Transaction::factory()->create(['date' => '2026-10-04', 'category_id' => $syukur->id, 'cash_account_id' => $account->id, 'amount' => 2_000_000]);

        $this->getJson('/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31')
            ->assertJsonPath('income', 2_000_000)
            ->assertJsonPath('non_operational_in', 50_000_000)
            ->assertJsonPath('change', 52_000_000);

        $this->getJson('/api/reports/monthly?start_period=2026-10&end_period=2026-10')
            ->assertOk()
            ->assertJsonPath('months.0.income', 2_000_000)
            ->assertJsonCount(2, 'income');
    }

    public function test_transaction_changes_are_logged(): void
    {
        $tx = Transaction::factory()->create();
        $tx->update(['amount' => 123_000]);
        $tx->delete();

        $actions = ActivityLog::where('subject_type', $tx->getMorphClass())->where('subject_id', $tx->id)->pluck('action')->all();
        $this->assertEquals(['created', 'updated', 'deleted'], $actions);
    }

    public function test_viewer_and_sekretariat_cannot_create_transfer(): void
    {
        foreach ([UserRole::Viewer, UserRole::Sekretariat] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson('/api/transfers', [])->assertForbidden();
            $this->postJson('/api/transactions', [])->assertForbidden();
        }
    }
}
