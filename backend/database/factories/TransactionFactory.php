<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'type' => TransactionType::Income,
            'category_id' => Category::factory(),
            'cash_account_id' => CashAccount::factory(),
            'amount' => fake()->numberBetween(10, 500) * 10000,
            'description' => fake()->sentence(4),
        ];
    }

    public function expense(): static
    {
        return $this->state([
            'type' => TransactionType::Expense,
            'category_id' => Category::factory()->expense(),
        ]);
    }
}
