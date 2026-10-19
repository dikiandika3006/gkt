<?php

namespace Tests\Feature;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));

        foreach ([
            ['4-100', 'Perpuluhan', CategoryType::Income, CategoryNature::Operational],
            ['5-200', 'Pengeluaran', CategoryType::Expense, CategoryNature::Operational],
            ['5-290', 'Pengeluaran', CategoryType::Expense, CategoryNature::Operational],
            ['5-300', 'Biaya Sinodal', CategoryType::Expense, CategoryNature::Operational],
            ['7-200', 'Pinjaman', CategoryType::Both, CategoryNature::Pinjaman],
            ['TRF-KELUAR', 'Mutasi Antar Kas', CategoryType::Expense, CategoryNature::Transfer],
        ] as [$code, $group, $type, $nature]) {
            Category::factory()->create(compact('code', 'group', 'type', 'nature'));
        }
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Kategori Uji',
            'group' => 'Pengeluaran',
            'type' => 'expense',
            'nature' => 'operational',
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_existing_group_continues_its_last_number_and_ignores_client_code(): void
    {
        $this->postJson('/api/categories', $this->payload(['code' => 'ASAL-999']))
            ->assertCreated()
            ->assertJsonPath('data.code', '5-291');

        $this->postJson('/api/categories', $this->payload())->assertJsonPath('data.code', '5-292');
    }

    public function test_new_group_gets_next_hundred_block_by_type_and_nature(): void
    {
        $this->postJson('/api/categories', $this->payload(['group' => 'Pembangunan']))->assertJsonPath('data.code', '5-400');
        $this->postJson('/api/categories', $this->payload(['group' => 'Hibah', 'type' => 'income']))->assertJsonPath('data.code', '4-200');
        $this->postJson('/api/categories', $this->payload(['group' => 'Komisi', 'type' => 'both']))->assertJsonPath('data.code', '6-100');
        $this->postJson('/api/categories', $this->payload(['group' => 'Titipan', 'type' => 'both', 'nature' => 'titipan']))
            ->assertJsonPath('data.code', '7-300');
    }

    public function test_update_never_changes_code(): void
    {
        $category = Category::where('code', '5-200')->first();

        $this->putJson("/api/categories/{$category->id}", $this->payload(['code' => '9-999', 'name' => 'Nama Baru']))
            ->assertOk()
            ->assertJsonPath('data.code', '5-200')
            ->assertJsonPath('data.name', 'Nama Baru');
    }

    public function test_next_code_preview_matches_what_store_assigns(): void
    {
        $this->getJson('/api/categories/next-code?'.http_build_query($this->payload()))
            ->assertOk()
            ->assertExactJson(['code' => '5-291']);

        $this->getJson('/api/categories/next-code?group=Pengeluaran')->assertUnprocessable();
    }

    public function test_viewer_cannot_preview_code(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));

        $this->getJson('/api/categories/next-code?'.http_build_query($this->payload()))->assertForbidden();
    }
}
