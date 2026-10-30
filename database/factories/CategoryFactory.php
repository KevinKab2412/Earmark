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
            'direction' => 'expense',
            'icon' => null,
            'sort_order' => 0,
            'archived' => false,
            'notes' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Category $category): void {
            if ($category->category_group_id !== null) {
                return;
            }

            $groupName = $category->direction === 'income' ? 'Income' : 'Other';
            $group = CategoryGroup::query()->firstOrCreate(
                ['household_id' => $category->household_id, 'name' => $groupName],
                ['sort_order' => $category->direction === 'income' ? 0 : 100, 'archived' => false],
            );

            $category->update(['category_group_id' => $group->id]);
        });
    }
}
