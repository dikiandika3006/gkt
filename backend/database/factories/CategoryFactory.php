<?php

namespace Database\Factories;

use App\Enums\CategoryNature;
use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CAT-###')),
            'name' => fake()->words(2, true),
            'group' => 'Persembahan Syukur',
            'type' => CategoryType::Income,
            'nature' => CategoryNature::Operational,
            'is_active' => true,
        ];
    }

    public function expense(): static
    {
        return $this->state(['type' => CategoryType::Expense, 'group' => 'Pengeluaran']);
    }

    public function both(): static
    {
        return $this->state(['type' => CategoryType::Both, 'group' => 'Komisi']);
    }

    public function nature(CategoryNature $nature): static
    {
        return $this->state(['nature' => $nature]);
    }
}
