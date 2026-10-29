<?php

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\CategoryNature;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\OfferingFund;
use App\Models\OfferingReport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OfferingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfferingReportTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $church;

    private OfferingFund $diakonia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->church = CashAccount::factory()->create(['code' => 'BANK-01', 'type' => CashAccountType::Bank]);
        // Pos bawaan dari migrasi; Diakonia diberi rekening luar.
        $this->diakonia = tap(OfferingFund::where('name', 'Diakonia')->firstOrFail())->update(['bank_name' => 'BRI', 'account_number' => '123-456', 'account_holder' => 'Komisi Diakonia']);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
    }

    /** Contoh rekap 23–29 Okt 2026 dari Excel warta. */
    private function payload(array $overrides = []): array
    {
        $line = fn (string $section, ?string $label, ?string $name, int $amount, array $extra = []) => [
            'section' => $section, 'label' => $label, 'name' => $name, 'amount' => $amount, ...$extra,
        ];

        return [
            'service_date' => '2026-10-25',
            'period_start' => '2026-10-23',
            'period_end' => '2026-10-29',
            'lines' => [
                $line('ibadah', 'Persembahan Syukur KU I', null, 1_270_500),
                $line('ibadah', 'Persembahan Syukur KU II', null, 805_000),
                $line('ibadah', 'Persembahan QRIS', null, 903_000),
                $line('ibadah', 'Kendaraan KU I', null, 697_000),
                $line('ibadah', 'Kendaraan KU II', null, 514_000),
                $line('syukur', null, 'TIMOTHY GUNAWAN', 600_000),
                $line('syukur', null, '127', 50_000),
                $line('syukur', null, 'DIKI ANDIKA', 300_000),
                $line('perpuluhan', null, '083/MST', 55_000),
                $line('perpuluhan', null, '129', 300_000),
                $line('perpuluhan', null, '061/JSM', 300_000),
                $line('perpuluhan', null, 'NADIA OVIANTI', 1_500_000),
                $line('lainnya', 'Diakonia', '129', 200_000, ['offering_fund_id' => $this->diakonia->id]),
            ],
            ...$overrides,
        ];
    }

    private function deposit(string $date, int $amount, string $nature = 'operational'): Transaction
    {
        $category = Category::factory()->create(['nature' => CategoryNature::from($nature)]);

        return Transaction::factory()->create([
            'date' => $date, 'type' => TransactionType::Income, 'category_id' => $category->id,
            'cash_account_id' => $this->church->id, 'amount' => $amount,
        ]);
    }

    public function test_totals_match_excel_and_other_destination_is_excluded_from_expected_deposit(): void
    {
        $this->postJson('/api/offering-reports', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.summary.sections.ibadah', 4_189_500)
            ->assertJsonPath('data.summary.sections.syukur', 950_000)
            ->assertJsonPath('data.summary.sections.perpuluhan', 2_155_000)
            ->assertJsonPath('data.summary.sections.lainnya', 200_000)
            ->assertJsonPath('data.summary.total_offering', 7_494_500)
            ->assertJsonPath('data.summary.total_expense', 0)
            ->assertJsonPath('data.summary.expected_deposit', 7_294_500)
            ->assertJsonPath('data.summary.other_destination_total', 200_000)
            ->assertJsonPath('data.summary.status', 'belum')
            // Tanpa tujuan → Rekening Gereja.
            ->assertJsonPath('data.lines.0.destination_cash_account_id', $this->church->id)
            ->assertJsonPath('data.lines.12.destination_cash_account_id', null);
    }

    public function test_expense_is_note_only(): void
    {
        $payload = $this->payload();
        $payload['lines'][] = ['section' => 'pengeluaran', 'label' => 'Bunga altar', 'amount' => 150_000];

        $this->postJson('/api/offering-reports', $payload)
            ->assertJsonPath('data.summary.total_expense', 150_000)
            ->assertJsonPath('data.summary.total_offering', 7_494_500)
            ->assertJsonPath('data.summary.expected_deposit', 7_294_500);
    }

    public function test_crosscheck_status_and_candidates(): void
    {
        $id = $this->postJson('/api/offering-reports', $this->payload())->json('data.id');

        $a = $this->deposit('2026-10-26', 5_000_000);
        $b = $this->deposit('2026-10-28', 2_294_500);
        $this->deposit('2026-10-24', 1_000); // sebelum rentang default (Senin–Sabtu setelah 25 Okt)
        $this->deposit('2026-10-27', 99_000, 'transfer'); // mutasi antar kas tidak ditampilkan
        Transaction::factory()->create(['date' => '2026-10-27', 'type' => TransactionType::Expense, 'cash_account_id' => $this->church->id]);

        $this->getJson("/api/offering-reports/{$id}/candidates")
            ->assertOk()
            ->assertJsonPath('start_date', '2026-10-26')
            ->assertJsonPath('end_date', '2026-10-31')
            ->assertJsonCount(2, 'data');

        $this->putJson("/api/offering-reports/{$id}/transactions", ['transaction_ids' => [$a->id]])
            ->assertJsonPath('data.summary.status', 'kurang')
            ->assertJsonPath('data.summary.difference', -2_294_500);

        $this->putJson("/api/offering-reports/{$id}/transactions", ['transaction_ids' => [$a->id, $b->id]])
            ->assertJsonPath('data.summary.status', 'cocok')
            ->assertJsonPath('data.summary.matched', 7_294_500);

        $extra = $this->deposit('2026-10-29', 10_000);
        $this->putJson("/api/offering-reports/{$id}/transactions", ['transaction_ids' => [$a->id, $b->id, $extra->id]])
            ->assertJsonPath('data.summary.status', 'lebih');
    }

    public function test_a_mutation_can_only_be_linked_to_one_report(): void
    {
        $first = $this->postJson('/api/offering-reports', $this->payload())->json('data.id');
        $second = $this->postJson('/api/offering-reports', $this->payload(['service_date' => '2026-11-01', 'period_start' => '2026-10-30', 'period_end' => '2026-11-05']))->json('data.id');
        $tx = $this->deposit('2026-10-26', 1_000_000);

        $this->putJson("/api/offering-reports/{$first}/transactions", ['transaction_ids' => [$tx->id]])->assertOk();
        $this->putJson("/api/offering-reports/{$second}/transactions", ['transaction_ids' => [$tx->id]])
            ->assertJsonValidationErrors('transaction_ids');

        // Sudah dipakai rekap lain → tidak muncul sebagai kandidat rekap kedua.
        $this->getJson("/api/offering-reports/{$second}/candidates?start_date=2026-10-01&end_date=2026-11-30")
            ->assertJsonMissing(['id' => $tx->id]);

        $transfer = $this->deposit('2026-10-27', 5_000, 'transfer');
        $this->putJson("/api/offering-reports/{$first}/transactions", ['transaction_ids' => [$transfer->id]])
            ->assertJsonValidationErrors('transaction_ids');
    }

    public function test_update_replaces_lines_and_delete_removes_report(): void
    {
        $id = $this->postJson('/api/offering-reports', $this->payload())->json('data.id');

        $this->putJson("/api/offering-reports/{$id}", $this->payload(['lines' => [
            ['section' => 'ibadah', 'label' => 'Persembahan Syukur KU I', 'amount' => 1_000_000],
        ]]))->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.summary.total_offering', 1_000_000);

        $this->deleteJson("/api/offering-reports/{$id}")->assertOk();
        $this->assertDatabaseCount('offering_report_lines', 0);
    }

    public function test_validation_requires_name_for_syukur_and_label_for_ibadah(): void
    {
        $this->postJson('/api/offering-reports', $this->payload(['lines' => [
            ['section' => 'syukur', 'amount' => 100_000],
            ['section' => 'ibadah', 'amount' => 100_000],
            ['section' => 'perpuluhan', 'name' => '129', 'amount' => 0],
        ]]))->assertJsonValidationErrors(['lines.0.name', 'lines.1.label', 'lines.2.amount']);
    }

    public function test_week_of_month_counts_by_date(): void
    {
        foreach (['2026-11-01' => 1, '2026-11-02' => 1, '2026-11-07' => 1, '2026-11-08' => 2, '2026-10-25' => 4, '2026-11-29' => 5] as $date => $week) {
            $this->assertSame($week, OfferingReportService::weekOfMonth($date), $date);
        }
    }

    public function test_template_builds_fixed_ibadah_lines_from_week_schedule(): void
    {
        // 25 Okt 2026 = Minggu ke-4 → pos Ibadah.
        $this->getJson('/api/offering-reports/template?service_date=2026-10-25')
            ->assertOk()
            ->assertJsonPath('week_of_month', 4)
            ->assertJsonPath('fund.name', 'Ibadah')
            ->assertJsonPath('default_cash_account_id', $this->church->id)
            ->assertJsonPath('ibadah_lines.*.label', ['Persembahan Syukur KU I', 'Persembahan Syukur KU II', 'Persembahan QRIS', 'Ibadah KU I', 'Ibadah KU II'])
            ->assertJsonPath('ibadah_lines.0.offering_fund_id', null);

        // 4 Okt 2026 = Minggu ke-1 → Diakonia, beserta rekeningnya.
        $this->getJson('/api/offering-reports/template?service_date=2026-10-04')
            ->assertJsonPath('fund.name', 'Diakonia')
            ->assertJsonPath('fund.account', 'BRI 123-456 a.n. Komisi Diakonia')
            ->assertJsonPath('ibadah_lines.3.label', 'Diakonia KU I')
            ->assertJsonPath('ibadah_lines.3.offering_fund_id', $this->diakonia->id);
    }

    public function test_destination_is_decided_by_server(): void
    {
        $other = CashAccount::factory()->create(['type' => CashAccountType::Bank]);

        $data = $this->postJson('/api/offering-reports', $this->payload(['service_date' => '2026-10-04', 'lines' => [
            // Tujuan dari client diabaikan: syukur/perpuluhan/ibadah biasa selalu ke Rekening Utama.
            ['section' => 'syukur', 'name' => 'A', 'amount' => 100_000, 'destination_cash_account_id' => $other->id],
            ['section' => 'perpuluhan', 'name' => 'B', 'amount' => 200_000, 'offering_fund_id' => $this->diakonia->id],
            ['section' => 'ibadah', 'label' => 'Persembahan Syukur KU I', 'amount' => 300_000],
            ['section' => 'ibadah', 'label' => 'Diakonia KU I', 'amount' => 400_000, 'offering_fund_id' => $this->diakonia->id],
        ]]))->assertCreated()->json('data');

        $this->assertSame(1, $data['week_of_month']);
        $this->assertSame([$this->church->id, $this->church->id, $this->church->id, null], array_column($data['lines'], 'destination_cash_account_id'));
        $this->assertSame('BRI 123-456 a.n. Komisi Diakonia', $data['lines'][3]['destination_note']);
        $this->assertNull($data['lines'][1]['offering_fund_id']);
        $this->assertEquals(600_000, $data['summary']['expected_deposit']);
        $this->assertEquals(400_000, $data['summary']['other_destination_total']);
    }

    public function test_history_month_summary(): void
    {
        $this->postJson('/api/offering-reports', $this->payload(['lines' => [
            ['section' => 'ibadah', 'label' => 'KU Pagi', 'amount' => 500_000],
            ['section' => 'perpuluhan', 'name' => '129', 'amount' => 300_000],
        ]]));
        $this->postJson('/api/offering-reports', $this->payload(['service_date' => '2026-10-18', 'period_start' => '2026-10-16', 'period_end' => '2026-10-22']));

        $this->getJson('/api/offering-reports?year=2026&month=10')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.service_date', '2026-10-25')
            ->assertJsonPath('month_summary.weeks', 2)
            ->assertJsonPath('month_summary.total_offering', 800_000 + 7_494_500);

        $this->getJson('/api/offering-reports?year=2026&month=9')->assertJsonCount(0, 'data');
    }

    public function test_viewer_can_read_only_and_sekretariat_has_no_access(): void
    {
        $report = OfferingReport::create(['service_date' => '2026-10-25', 'period_start' => '2026-10-23', 'period_end' => '2026-10-29']);

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $this->getJson("/api/offering-reports/{$report->id}")->assertOk();
        $this->postJson('/api/offering-reports', $this->payload())->assertForbidden();
        $this->putJson("/api/offering-reports/{$report->id}/transactions", ['transaction_ids' => []])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Sekretariat]));
        $this->getJson("/api/offering-reports/{$report->id}")->assertForbidden();
        $this->getJson('/api/offering-reports')->assertForbidden();
        $this->postJson('/api/offering-reports', $this->payload())->assertForbidden();
    }
}
