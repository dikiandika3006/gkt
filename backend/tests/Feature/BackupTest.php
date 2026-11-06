<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Form Backup & Restore (About → Alt+F7). Backup/restore nyata khusus MySQL, diuji manual. */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_only_admin_can_reach_backup(): void
    {
        config(['backup.pin' => '1234']);
        $this->actingAsRole(UserRole::Bendahara);

        $this->postJson('/api/backup/unlock', ['pin' => '1234'])->assertForbidden();
        $this->getJson('/api/backup')->assertForbidden();
    }

    public function test_disabled_without_pin(): void
    {
        config(['backup.pin' => null]);
        $this->actingAsRole(UserRole::Admin);

        $this->postJson('/api/backup/unlock', ['pin' => ''])
            ->assertForbidden()
            ->assertJsonPath('message', 'Fitur backup belum diaktifkan (BACKUP_PIN kosong di .env server).');
        $this->getJson('/api/backup')->assertForbidden();
    }

    public function test_pin_unlocks_panel(): void
    {
        config(['backup.pin' => '1234']);
        $this->actingAsRole(UserRole::Admin);

        $this->getJson('/api/backup')->assertForbidden();
        $this->postJson('/api/backup/unlock', ['pin' => '0000'])->assertUnprocessable()->assertJsonPath('message', 'PIN salah.');
        $this->getJson('/api/backup')->assertForbidden();

        $this->postJson('/api/backup/unlock', ['pin' => '1234'])->assertOk();
        $this->getJson('/api/backup')->assertOk()->assertJsonStructure(['backups', 'database_size', 'upload_limit']);
    }

    public function test_rejects_unknown_file_names_and_missing_confirmation(): void
    {
        config(['backup.pin' => '1234']);
        $this->actingAsRole(UserRole::Admin);
        $this->postJson('/api/backup/unlock', ['pin' => '1234'])->assertOk();

        $this->get('/api/backup/'.urlencode('..%2F.env').'/download')->assertNotFound();
        $this->get('/api/backup/backup_20260101_000000.sql.gz/download')->assertNotFound();
        $this->deleteJson('/api/backup/rahasia.txt')->assertNotFound();

        $this->postJson('/api/backup/restore', ['konfirmasi' => 'restore', 'name' => 'backup_20260101_000000.sql.gz'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('konfirmasi');
        $this->postJson('/api/backup/restore', ['konfirmasi' => 'RESTORE', 'name' => 'backup_20260101_000000.sql.gz'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'File backup tidak ditemukan. Database tidak diubah.');
    }
}
