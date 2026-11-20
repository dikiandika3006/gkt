<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SystemUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Status database & Update Database di About. Backup nyata khusus MySQL (dilewati di SQLite). */
class SystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $deployedPath;

    private string $appliedPath;

    private ?string $appliedBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $service = app(SystemUpdateService::class);
        $this->deployedPath = $service->deployedCommitPath();
        $this->appliedPath = $service->lastAppliedPath();
        $this->assertFileDoesNotExist($this->deployedPath, 'Test ini tidak boleh dijalankan di server hasil deploy.');
        if (is_file($this->appliedPath)) {
            $this->appliedBackup = file_get_contents($this->appliedPath);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->deployedPath);
        if ($this->appliedBackup !== null) {
            file_put_contents($this->appliedPath, $this->appliedBackup);
        } else {
            @unlink($this->appliedPath);
        }
        parent::tearDown();
    }

    public function test_only_admin(): void
    {
        foreach ([UserRole::Bendahara, UserRole::Viewer, UserRole::Sekretariat] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/system/status')->assertForbidden();
            $this->postJson('/api/system/update')->assertForbidden();
        }
    }

    public function test_status_and_update_run_pending_migrations(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->getJson('/api/system/status')
            ->assertOk()
            ->assertJsonPath('database.ok', true)
            ->assertJsonPath('pending_migrations', [])
            ->assertJsonPath('needs_update', false);

        // Simulasikan deploy baru: satu migrasi belum jalan + commit deploy belum diterapkan.
        $this->rollbackLatest('2026_11_20_110000_add_difference_note_to_offering_reports_table');
        file_put_contents($this->deployedPath, 'abc1234');

        $this->getJson('/api/system/status')
            ->assertOk()
            ->assertJsonPath('pending_migrations', ['2026_11_20_110000_add_difference_note_to_offering_reports_table'])
            ->assertJsonPath('deployed_commit', 'abc1234')
            ->assertJsonPath('needs_update', true);

        $this->postJson('/api/system/update')
            ->assertOk()
            ->assertJsonPath('backup', null)
            ->assertJsonPath('status.needs_update', false)
            ->assertJsonPath('status.last_applied_commit', 'abc1234');

        $this->assertTrue(Schema::hasColumn('offering_reports', 'difference_note'));
        $this->assertSame('abc1234', file_get_contents($this->appliedPath));
    }

    private function rollbackLatest(string $migration): void
    {
        Schema::table('offering_reports', fn ($t) => $t->dropColumn('difference_note'));
        \DB::table('migrations')->where('migration', $migration)->delete();
    }
}
