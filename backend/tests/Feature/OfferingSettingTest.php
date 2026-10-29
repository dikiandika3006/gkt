<?php

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\OfferingFund;
use App\Models\OfferingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfferingSettingTest extends TestCase
{
    use RefreshDatabase;

    private function current(): array
    {
        return $this->getJson('/api/offering-settings')->assertOk()->json();
    }

    public function test_defaults_are_seeded(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $s = $this->current();

        $names = collect($s['funds'])->pluck('name', 'id');
        $this->assertSame(['Diakonia', 'PI Misi', 'Kendaraan', 'Ibadah'], $names->values()->all());
        $this->assertSame(['Diakonia', 'PI Misi', 'Kendaraan', 'Ibadah', 'Diakonia'], collect($s['schedule'])->map(fn ($id) => $names[$id])->values()->all());
        $this->assertSame(['KU I', 'KU II'], $s['services']);
    }

    public function test_save_funds_schedule_and_services_with_new_fund(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $main = CashAccount::factory()->create(['type' => CashAccountType::Bank]);
        $s = $this->current();
        $funds = collect($s['funds']);

        $payload = [
            'funds' => [
                ...$funds->map(fn ($f) => [...$f, 'cash_account_id' => $f['name'] === 'Ibadah' ? $main->id : null, 'bank_name' => $f['name'] === 'PI Misi' ? 'Mandiri' : null, 'account_number' => $f['name'] === 'PI Misi' ? '777' : null])->all(),
                ['key' => 'baru-1', 'name' => 'Pembangunan', 'bank_name' => 'BNI', 'account_number' => '999', 'is_active' => true],
            ],
            'schedule' => [1 => $funds[0]['id'], 2 => $funds[1]['id'], 3 => 'baru-1', 4 => $funds[3]['id'], 5 => null],
            'services' => ['KU I', 'KU II', 'KU Sore'],
        ];

        $saved = $this->putJson('/api/offering-settings', $payload)->assertOk()->json();
        $byName = collect($saved['funds'])->keyBy('name');

        $this->assertSame('Mandiri 777', $byName['PI Misi']['account']);
        $this->assertSame($main->id, $byName['Ibadah']['cash_account_id']);
        $this->assertSame($byName['Pembangunan']['id'], $saved['schedule'][3]);
        $this->assertNull($saved['schedule'][5]);
        $this->assertSame(['KU I', 'KU II', 'KU Sore'], $saved['services']);

        // Template ikut memakai kebaktian & jadwal baru (15 Nov 2026 = Minggu ke-3).
        $this->getJson('/api/offering-reports/template?service_date=2026-11-15')
            ->assertJsonPath('fund.name', 'Pembangunan')
            ->assertJsonCount(3 + 1 + 3, 'ibadah_lines');
    }

    public function test_used_fund_cannot_be_removed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $diakonia = OfferingFund::where('name', 'Diakonia')->first();
        $report = OfferingReport::create(['service_date' => '2026-10-04', 'period_start' => '2026-10-02', 'period_end' => '2026-10-08']);
        $report->lines()->create(['section' => 'ibadah', 'offering_fund_id' => $diakonia->id, 'label' => 'Diakonia KU I', 'amount' => 1000]);

        $s = $this->current();
        $this->putJson('/api/offering-settings', [
            'funds' => collect($s['funds'])->reject(fn ($f) => $f['name'] === 'Diakonia')->values()->all(),
            'schedule' => [1 => null, 2 => null, 3 => null, 4 => null, 5 => null],
            'services' => ['KU I'],
        ])->assertJsonValidationErrors('funds');

        $this->assertDatabaseHas('offering_funds', ['id' => $diakonia->id]);
    }

    public function test_viewer_reads_only_and_sekretariat_has_no_access(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $this->getJson('/api/offering-settings')->assertOk();
        $this->putJson('/api/offering-settings', [])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Sekretariat]));
        $this->getJson('/api/offering-settings')->assertForbidden();
    }
}
