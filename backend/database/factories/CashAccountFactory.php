<?php

namespace Database\Factories;

use App\Enums\CashAccountType;
use App\Models\CashAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashAccount>
 */
class CashAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('KAS-###')),
            'name' => 'Kas '.fake()->word(),
            'type' => CashAccountType::Cash,
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }
}
