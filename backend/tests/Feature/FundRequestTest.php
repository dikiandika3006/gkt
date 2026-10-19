<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FundRequestTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $bank;

    private CashAccount $petty;

    private Category $atk;

    private User $bendahara;

    private User $sekretariat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = CashAccount::factory()->create(['opening_balance' => 10_000_000]);
        $this->petty = CashAccount::factory()->create(['opening_balance' => 0]);
        $this->atk = Category::factory()->expense()->create(['name' => 'ATK']);
        $this->bendahara = User::factory()->create(['role' => UserRole::Bendahara]);
        $this->sekretariat = User::factory()->create(['role' => UserRole::Sekretariat]);
    }

    private function createRequest(string $period, int $amount): int
    {
        Sanctum::actingAs($this->sekretariat);

        return $this->postJson('/api/fund-requests', [
            'cash_account_id' => $this->petty->id,
            'period' => $period,
            'request_date' => $period.'-01',
            'items' => [['description' => 'Operasional sekretariat', 'amount' => $amount]],
        ])->assertCreated()->json('data.id');
    }

    private function transfer(int $requestId, int $amount, string $date): void
    {
        Sanctum::actingAs($this->bendahara);
        $this->postJson("/api/fund-requests/{$requestId}/transfers", [
            'date' => $date, 'from_cash_account_id' => $this->bank->id, 'amount' => $amount,
        ])->assertOk();
    }

    private function addNota(int $requestId, int $price, string $date, int $qty = 1): array
    {
        Sanctum::actingAs($this->sekretariat);

        return $this->postJson("/api/fund-requests/{$requestId}/report/items", [
            'date' => $date, 'category_id' => $this->atk->id, 'description' => 'Kertas', 'qty' => $qty, 'unit_price' => $price,
        ])->assertOk()->json('data');
    }

    public function test_full_cycle_with_surplus_carried_to_next_month(): void
    {
        // Oktober: rencana 3 jt, transfer 3 jt, nota 2,6 jt → sisa 400 rb.
        $oct = $this->createRequest('2026-10', 3_000_000);
        $this->getJson("/api/fund-requests/{$oct}")->assertJsonPath('data.summary.suggested_transfer', 3_000_000)
            ->assertJsonPath('data.summary.status', 'diajukan');

        $this->transfer($oct, 3_000_000, '2026-10-01');
        $data = $this->addNota($oct, 1_300_000, '2026-10-10', 2);

        $this->assertEquals(2_600_000, $data['summary']['reported']);
        $this->assertEquals(400_000, $data['summary']['difference']);
        $this->assertEquals(400_000, $data['summary']['closing_balance']);
        $this->assertEquals('lpj_sebagian', $data['summary']['status']);

        // Nota tercatat sebagai pengeluaran operasional gereja di bulan nota, dari kas sekretariat.
        $this->getJson('/api/reports/summary?start_date=2026-10-01&end_date=2026-10-31')
            ->assertJsonPath('expense', 2_600_000)
            ->assertJsonPath('closing_balance', 7_400_000);

        // November: rencana 3,2 jt → saran transfer 2,8 jt.
        $nov = $this->createRequest('2026-11', 3_200_000);
        $this->getJson("/api/fund-requests/{$nov}")
            ->assertJsonPath('data.summary.carry_over', 400_000)
            ->assertJsonPath('data.summary.suggested_transfer', 2_800_000);
    }

    public function test_deficit_increases_next_suggested_transfer_and_late_lpj_updates_it(): void
    {
        $oct = $this->createRequest('2026-10', 3_000_000);
        $this->transfer($oct, 3_000_000, '2026-10-01');

        // Pengajuan November dibuat sebelum LPJ Oktober masuk.
        $nov = $this->createRequest('2026-11', 3_200_000);
        $this->getJson("/api/fund-requests/{$nov}")->assertJsonPath('data.summary.suggested_transfer', 200_000);

        // LPJ Oktober menyusul di awal November: realisasi 3,3 jt → kurang 300 rb.
        $this->addNota($oct, 3_300_000, '2026-10-28');
        $this->getJson("/api/fund-requests/{$nov}")
            ->assertJsonPath('data.summary.carry_over', -300_000)
            ->assertJsonPath('data.summary.suggested_transfer', 3_500_000);
    }

    public function test_editing_and_deleting_nota_keeps_transaction_in_sync(): void
    {
        $oct = $this->createRequest('2026-10', 1_000_000);
        $item = $this->addNota($oct, 100_000, '2026-10-05')['report']['items'][0];
        $tx = Transaction::where('fund_report_item_id', $item['id'])->firstOrFail();
        $this->assertEquals($this->petty->id, $tx->cash_account_id);

        $this->putJson("/api/fund-report-items/{$item['id']}", [
            'date' => '2026-10-06', 'category_id' => $this->atk->id, 'description' => 'Tinta', 'qty' => 3, 'unit_price' => 50_000,
        ])->assertOk()->assertJsonPath('data.summary.reported', 150_000);
        $this->assertEquals(150_000, (float) $tx->fresh()->amount);

        // Transaksi LPJ tidak bisa diubah dari menu transaksi biasa.
        Sanctum::actingAs($this->bendahara);
        $this->putJson("/api/transactions/{$tx->id}", [
            'date' => '2026-10-06', 'type' => 'expense', 'category_id' => $this->atk->id, 'cash_account_id' => $this->petty->id,
            'amount' => 1, 'description' => 'x',
        ])->assertUnprocessable();

        Sanctum::actingAs($this->sekretariat);
        $this->deleteJson("/api/fund-report-items/{$item['id']}")->assertOk()->assertJsonPath('data.summary.reported', 0);
        $this->assertSoftDeleted($tx);
    }

    public function test_report_can_be_marked_complete(): void
    {
        $oct = $this->createRequest('2026-10', 1_000_000);
        $this->putJson("/api/fund-requests/{$oct}/report", ['notes' => 'Lengkap', 'submitted' => true])
            ->assertOk()->assertJsonPath('data.summary.status', 'selesai');
    }

    public function test_period_must_be_unique_per_cash_account(): void
    {
        $this->createRequest('2026-10', 1_000_000);
        $this->postJson('/api/fund-requests', [
            'cash_account_id' => $this->petty->id, 'period' => '2026-10', 'request_date' => '2026-10-02',
            'items' => [['description' => 'x', 'amount' => 1]],
        ])->assertJsonValidationErrors('period');
    }

    public function test_sekretariat_cannot_record_transfer_and_viewer_cannot_create_request(): void
    {
        $oct = $this->createRequest('2026-10', 1_000_000);
        $this->postJson("/api/fund-requests/{$oct}/transfers", [
            'date' => '2026-10-01', 'from_cash_account_id' => $this->bank->id, 'amount' => 1,
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $this->postJson('/api/fund-requests', [])->assertForbidden();
        $this->getJson("/api/fund-requests/{$oct}")->assertOk();
    }

    public function test_secretariat_report_and_export(): void
    {
        $oct = $this->createRequest('2026-10', 1_000_000);
        $this->transfer($oct, 1_000_000, '2026-10-01');
        $this->addNota($oct, 250_000, '2026-10-05');

        $this->getJson("/api/reports/secretariat?cash_account_id={$this->petty->id}&start_period=2026-01&end_period=2026-12")
            ->assertOk()
            ->assertJsonPath('total_reported', 250_000)
            ->assertJsonPath('by_category.0.periods.2026-10', 250_000)
            ->assertJsonPath('requests.0.closing_balance', 750_000);

        $this->get("/api/reports/secretariat/export?cash_account_id={$this->petty->id}&start_period=2026-01&end_period=2026-12")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_deleting_request_removes_transfers_and_lpj_transactions(): void
    {
        $oct = $this->createRequest('2026-10', 1_000_000);
        $this->transfer($oct, 1_000_000, '2026-10-01');
        $this->addNota($oct, 100_000, '2026-10-05');

        $this->deleteJson("/api/fund-requests/{$oct}")->assertOk();
        $this->assertEquals(0, Transaction::count());
    }
}
