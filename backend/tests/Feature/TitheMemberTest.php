<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OfferingReport;
use App\Models\RolePermission;
use App\Models\TitheMember;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TitheMemberTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param list<list<mixed>> $rows */
    private function xlsx(array $rows, string $sheet = 'Master No Perpuluhan'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Lain');
        $ws = $book->createSheet()->setTitle($sheet);
        $ws->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'tithe').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'master.xlsx', null, null, true);
    }

    public function test_crud_label_and_validation(): void
    {
        $this->actingAsRole(UserRole::Bendahara);

        $this->postJson('/api/tithe-members', ['code' => 1, 'name' => 'Budi & Rina', 'alias' => ' SVN '])
            ->assertCreated()
            ->assertJsonPath('data.label', '001/SVN')
            ->assertJsonPath('data.alias', 'SVN');

        $this->postJson('/api/tithe-members', ['code' => 1, 'name' => 'Lain', 'alias' => 'A/B'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'alias']);

        $noAlias = $this->postJson('/api/tithe-members', ['code' => 151, 'name' => 'Anton', 'alias' => ''])
            ->assertCreated()
            ->assertJsonPath('data.label', '151')
            ->json('data.id');

        $this->putJson("/api/tithe-members/{$noAlias}", ['code' => 151, 'name' => 'Anton', 'alias' => 'CLA', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.label', '151/CLA')
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/tithe-members?search=budi')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/tithe-members?search=151')->assertOk()->assertJsonPath('data.0.name', 'Anton');

        $this->deleteJson("/api/tithe-members/{$noAlias}")->assertOk();
        $this->assertDatabaseCount('tithe_members', 1);
    }

    public function test_access_defaults_admin_and_bendahara_only(): void
    {
        $member = TitheMember::factory()->create();

        foreach ([UserRole::Viewer, UserRole::Sekretariat] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/tithe-members')->assertForbidden();
            $this->postJson('/api/tithe-members', ['code' => 5, 'name' => 'X'])->assertForbidden();
            $this->deleteJson("/api/tithe-members/{$member->id}")->assertForbidden();
        }

        $this->actingAsRole(UserRole::Admin);
        $this->getJson('/api/tithe-members')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_import_from_excel_skips_empty_numbers_and_updates_on_reimport(): void
    {
        $this->actingAsRole(UserRole::Bendahara);
        TitheMember::factory()->create(['code' => 2, 'name' => 'Nama Lama', 'alias' => 'OLD']);

        $file = $this->xlsx([
            ['No', 'Nama', 'Kode', 'ALIAS'],
            [1, 'Budi Santoso & Rina Wati', 1, 'Budiman'],
            [2, 'Sari Dewi', 2, 'LL'],
            [12, null, 12, null],
            [151, 'Anton', 151, null],
            [null, null, null, null],
        ]);

        $this->post('/api/tithe-members/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame('001/Budiman', TitheMember::where('code', 1)->first()->label);
        $this->assertSame('Sari Dewi', TitheMember::where('code', 2)->first()->name);
        $this->assertSame('151', TitheMember::where('code', 151)->first()->label);
        $this->assertDatabaseCount('tithe_members', 3);

        $this->post('/api/tithe-members/import', ['file' => $this->xlsx([['Foo', 'Bar'], [1, 2]])], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_counterparty_suggestions_include_master_and_hide_names_without_permission(): void
    {
        TitheMember::factory()->create(['code' => 1, 'name' => 'Budi Santoso', 'alias' => 'SVN']);
        TitheMember::factory()->create(['code' => 2, 'name' => 'Sari Dewi', 'alias' => 'LL']);
        TitheMember::factory()->create(['code' => 3, 'name' => 'Nonaktif', 'alias' => 'NA', 'is_active' => false]);
        Transaction::factory()->create(['counterparty' => 'Toko Sinar']);

        $this->actingAsRole(UserRole::Bendahara);
        $this->getJson('/api/counterparties')
            ->assertOk()
            ->assertJsonPath('data.0', 'Toko Sinar')
            ->assertJsonCount(2, 'members')
            ->assertJsonPath('members.0', ['label' => '001/SVN', 'name' => 'Budi Santoso']);
        $this->getJson('/api/counterparties?search=001/SV')->assertOk()->assertJsonCount(1, 'members');
        $this->getJson('/api/counterparties?search=sari')->assertOk()->assertJsonPath('members.0.label', '002/LL');

        // Viewer: boleh lihat Mutasi, tanpa hak Nomor Perpuluhan → hanya label, tidak bisa cari pakai nama lengkap.
        $this->actingAsRole(UserRole::Viewer);
        $this->getJson('/api/counterparties')->assertOk()->assertJsonPath('members.0', ['label' => '001/SVN', 'name' => null]);
        $this->getJson('/api/counterparties?search=sari')->assertOk()->assertJsonCount(0, 'members');

        // Hanya Rekap Persembahan (tanpa Mutasi): dapat saran master, tanpa riwayat transaksi.
        RolePermission::updateOrCreate(['role' => UserRole::Viewer->value, 'module' => 'transactions'], ['level' => 'none']);
        app()->forgetInstance(PermissionService::class);
        $this->getJson('/api/counterparties')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(2, 'members');

        $this->actingAsRole(UserRole::Sekretariat);
        $this->getJson('/api/counterparties')->assertForbidden();
    }

    public function test_donor_recap_groups_by_master_number(): void
    {
        TitheMember::factory()->create(['code' => 1, 'name' => 'Budi Santoso', 'alias' => 'SVN']);
        $report = OfferingReport::create(['service_date' => '2026-10-04', 'week_of_month' => 1, 'period_start' => '2026-10-04', 'period_end' => '2026-10-04']);
        foreach ([['perpuluhan', '001/SVN', 500_000], ['perpuluhan', '1/svn', 200_000], ['syukur', 'Budi Santoso', 50_000], ['perpuluhan', 'Bpk Andi', 100_000]] as $i => [$section, $name, $amount]) {
            $report->lines()->create(['section' => $section, 'label' => $section, 'name' => $name, 'amount' => $amount, 'sort_order' => $i]);
        }

        $this->actingAsRole(UserRole::Admin);
        $this->getJson('/api/reports/donors?year=2026')
            ->assertOk()
            ->assertJsonPath('totals.donors', 2)
            ->assertJsonPath('rows.0.name', '001/SVN')
            ->assertJsonPath('rows.0.code', 1)
            ->assertJsonPath('rows.0.member_name', 'Budi Santoso')
            ->assertJsonPath('rows.0.perpuluhan', 700_000)
            ->assertJsonPath('rows.0.syukur', 50_000)
            ->assertJsonPath('rows.1.name', 'Bpk Andi')
            ->assertJsonPath('rows.1.member_name', null);
        $this->get('/api/reports/donors/export?year=2026')->assertOk();
    }
}
