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

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'date' => '2026-10-05',
            'type' => 'income',
            'category_id' => Category::factory()->create()->id,
            'cash_account_id' => CashAccount::factory()->create()->id,
            'amount' => 150000,
            'description' => 'Persembahan Minggu I',
            ...$overrides,
        ];
    }

    public function test_bendahara_can_create_transaction_with_generated_number(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));

        $this->postJson('/api/transactions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.number', 'KM-202610-0001')
            ->assertJsonPath('data.amount', 150000);

        $this->postJson('/api/transactions', $this->payload())
            ->assertJsonPath('data.number', 'KM-202610-0002');
    }

    public function test_viewer_cannot_write_but_can_read(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));

        $this->postJson('/api/transactions', $this->payload())->assertForbidden();
        $this->getJson('/api/transactions')->assertOk();
    }

    public function test_category_type_must_match_transaction_type(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $expenseCategory = Category::factory()->expense()->create();

        $this->postJson('/api/transactions', $this->payload(['category_id' => $expenseCategory->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_amount_must_be_positive(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/transactions', $this->payload(['amount' => 0]))
            ->assertJsonValidationErrors('amount');
    }

    public function test_update_and_soft_delete(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $transaction = Transaction::factory()->create();

        $this->putJson("/api/transactions/{$transaction->id}", [
            'date' => $transaction->date->toDateString(),
            'type' => 'income',
            'category_id' => $transaction->category_id,
            'cash_account_id' => $transaction->cash_account_id,
            'amount' => 99000,
            'description' => 'Diubah',
        ])->assertOk()->assertJsonPath('data.amount', 99000);

        $this->deleteJson("/api/transactions/{$transaction->id}")->assertOk();
        $this->assertSoftDeleted($transaction);
    }

    public function test_category_in_use_cannot_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $transaction = Transaction::factory()->create();

        $this->deleteJson("/api/categories/{$transaction->category_id}")->assertUnprocessable();
    }

    public function test_only_admin_can_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $this->getJson('/api/users')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/api/users')->assertOk();
    }
}
