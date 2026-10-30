<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryBudgetMove;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryBudgetMove>
 */
class CategoryBudgetMoveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'from_category_id' => Category::factory(),
            'to_category_id' => Category::factory(),
            'effective_month' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'amount' => fake()->numberBetween(1, 100_000),
            'memo' => null,
            'created_by_user_id' => User::factory(),
        ];
    }
}
