<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_secretariat_only_reaches_fund_requests_and_its_report(): void
    {
        $petty = CashAccount::factory()->create(['name' => 'Kas Sekretariat']);
        $this->actingAsRole(UserRole::Sekretariat);

        foreach (['/api/dashboard', '/api/insights', '/api/transactions', '/api/counterparties', '/api/reports/monthly', '/api/reports/summary', '/api/recurring-expenses', '/api/budgets', '/api/transactions/export'] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
        $this->get('/api/reports/monthly/export')->assertForbidden();

        $this->getJson('/api/fund-requests')->assertOk();
        $this->getJson("/api/fund-requests/summary?cash_account_id={$petty->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $petty->id)
            ->assertJsonPath('data.0.active_requests', 0);
        $this->getJson("/api/reports/secretariat?cash_account_id={$petty->id}")->assertOk();
        // Dropdown kategori & kas tetap tersedia untuk form pengajuan/LPJ.
        $this->getJson('/api/categories')->assertOk();
        $this->getJson('/api/cash-accounts')->assertOk();

        $this->postJson('/api/fund-requests', [
            'cash_account_id' => $petty->id, 'period' => '2026-11', 'request_date' => '2026-11-01',
            'items' => [['description' => 'ATK', 'amount' => 100_000]],
        ])->assertCreated();
    }

    public function test_me_returns_permissions(): void
    {
        $this->actingAsRole(UserRole::Sekretariat);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.permissions.fund_requests', 'manage')
            ->assertJsonPath('data.permissions.report_secretariat', 'view')
            ->assertJsonPath('data.permissions.dashboard', 'none');
    }

    public function test_bendahara_and_viewer_keep_previous_access(): void
    {
        $this->actingAsRole(UserRole::Bendahara);
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/reports/monthly')->assertOk();
        $this->postJson('/api/categories', [])->assertUnprocessable(); // lolos hak akses, gagal validasi

        $this->actingAsRole(UserRole::Viewer);
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/transactions')->assertOk();
        $this->postJson('/api/categories', [])->assertForbidden();
    }

    public function test_admin_changes_matrix_and_it_applies_immediately(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $matrix = $this->getJson('/api/permissions')->assertOk()->json();
        $this->assertSame('none', $matrix['permissions']['sekretariat']['dashboard']);
        $this->assertSame(['none', 'view'], collect($matrix['modules'])->firstWhere('key', 'dashboard')['levels']);

        $this->putJson('/api/permissions', ['permissions' => ['sekretariat' => ['dashboard' => 'view', 'categories' => 'manage']]])
            ->assertOk()
            ->assertJsonPath('permissions.sekretariat.dashboard', 'view');

        $this->actingAsRole(UserRole::Sekretariat);
        $this->getJson('/api/dashboard')->assertOk();
        $category = Category::factory()->create();
        $this->getJson("/api/categories/{$category->id}")->assertOk();
    }

    public function test_matrix_validation_and_admin_only(): void
    {
        $this->actingAsRole(UserRole::Admin);

        // Dashboard hanya bisa dilihat, tidak bisa dikelola.
        $this->putJson('/api/permissions', ['permissions' => ['viewer' => ['dashboard' => 'manage']]])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.viewer.dashboard');
        // Admin selalu penuh, tidak bisa diatur.
        $this->putJson('/api/permissions', ['permissions' => ['admin' => ['dashboard' => 'none']]])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.admin');
        $this->putJson('/api/permissions', ['permissions' => ['viewer' => ['unknown' => 'view']]])
            ->assertUnprocessable();
        $this->getJson('/api/dashboard')->assertOk();

        $this->actingAsRole(UserRole::Bendahara);
        $this->getJson('/api/permissions')->assertForbidden();
        $this->putJson('/api/permissions', ['permissions' => []])->assertForbidden();
    }

    public function test_permission_changes_are_logged(): void
    {
        $this->actingAsRole(UserRole::Admin);
        $this->putJson('/api/permissions', ['permissions' => ['viewer' => ['budgets' => 'none']]])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['subject_type' => RolePermission::class, 'action' => 'updated']);
    }
}
