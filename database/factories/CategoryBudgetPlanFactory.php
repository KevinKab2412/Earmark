<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryBudgetPlan>
 */
class CategoryBudgetPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'effective_month' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'planned_amount' => fake()->numberBetween(0, 100_000),
            'created_by_user_id' => null,
        ];
    }
}
