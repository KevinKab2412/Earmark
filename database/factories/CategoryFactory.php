<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->unique()->words(2, true),
            'category_group_id' => null,
            'kind' => 'standard',
            'icon' => null,
            'sort_order' => 0,
            'archived' => false,
            'notes' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Category $category): void {
            if ($category->category_group_id !== null) {
                return;
            }

            $group = CategoryGroup::query()->firstOrCreate(
                ['household_id' => $category->household_id, 'name' => 'Other'],
                ['direction' => 'expense', 'sort_order' => 100, 'archived' => false],
            );

            $category->category_group_id = $group->id;
        });
    }
}
