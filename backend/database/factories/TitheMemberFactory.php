<?php

namespace Database\Factories;

use App\Models\TitheMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TitheMember>
 */
class TitheMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numberBetween(1, 999),
            'name' => fake()->name(),
            'alias' => strtoupper(fake()->lexify('???')),
            'is_active' => true,
        ];
    }
}
