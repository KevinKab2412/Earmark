<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryGroup;
use App\Models\CategoryRolloverSetting;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class CategoryGroupSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, sort_order: int, direction: string, categories: list<string>, category_directions?: array<string, string>, category_icons?: array<string, string>, rollover: bool}>
     */
    private array $defaults = [
        'income' => ['name' => 'Income', 'sort_order' => 0, 'direction' => 'income', 'categories' => ['Paychecks', 'Interest', 'Other Income'], 'rollover' => false],
        'housing' => ['name' => 'Housing', 'sort_order' => 10, 'direction' => 'expense', 'categories' => ['Rent or Mortgage', 'Home Supplies', 'Repairs and Maintenance'], 'rollover' => false],
        'transportation' => ['name' => 'Transportation', 'sort_order' => 20, 'direction' => 'expense', 'categories' => ['Gas', 'Auto Maintenance', 'Public Transit'], 'rollover' => false],
        'food' => ['name' => 'Food & Dining', 'sort_order' => 30, 'direction' => 'expense', 'categories' => ['Groceries', 'Restaurants', 'Coffee Shops'], 'rollover' => false],
        'lifestyle' => ['name' => 'Lifestyle', 'sort_order' => 40, 'direction' => 'expense', 'categories' => ['Shopping', 'Entertainment', 'Travel'], 'rollover' => false],
        'health' => ['name' => 'Health & Wellness', 'sort_order' => 50, 'direction' => 'expense', 'categories' => ['Medical', 'Prescriptions', 'Fitness'], 'rollover' => false],
        'debt' => ['name' => 'Debt', 'sort_order' => 60, 'direction' => 'expense', 'categories' => ['Loan Payments'], 'rollover' => false],
        'savings' => ['name' => 'Savings Goals', 'sort_order' => 70, 'direction' => 'expense', 'categories' => ['Emergency Fund', 'Vacation', 'Home Down Payment'], 'rollover' => true],
        'bills' => ['name' => 'Bills', 'sort_order' => 80, 'direction' => 'expense', 'categories' => ['Electricity', 'Internet', 'Phone'], 'rollover' => false],
        'other' => ['name' => 'Other', 'sort_order' => 100, 'direction' => 'expense', 'categories' => [], 'rollover' => false],
        'transfers' => ['name' => 'Transfers', 'sort_order' => 110, 'direction' => 'transfer', 'categories' => ['Transfer', 'Credit Card Payment', 'Balance Adjustments'], 'category_directions' => ['Balance Adjustments' => 'adjustment'], 'category_icons' => ['Transfer' => '🔁', 'Credit Card Payment' => '💳', 'Balance Adjustments' => '⚖️'], 'rollover' => false],
    ];

    public function run(?Household $household = null): void
    {
        $households = $household ? collect([$household]) : Household::query()->get();
        $effectiveMonth = CarbonImmutable::now()->startOfMonth()->toDateString();

        foreach ($households as $target) {
            foreach ($this->defaults as $definition) {
                $group = CategoryGroup::query()->firstOrCreate(
                    ['household_id' => $target->id, 'name' => $definition['name']],
                    ['sort_order' => $definition['sort_order'], 'archived' => false],
                );

                foreach ($definition['categories'] as $sortOrder => $name) {
                    $category = Category::query()->firstOrNew(['household_id' => $target->id, 'name' => $name]);
                    if ($category->starting_month === null) {
                        $category->starting_month = $effectiveMonth;
                    }
                    if ($category->starting_balance === null) {
                        $category->starting_balance = 0;
                    }
                    $category->fill([
                        'category_group_id' => $group->id,
                        'direction' => $definition['category_directions'][$name] ?? $definition['direction'],
                        'icon' => $definition['category_icons'][$name] ?? null,
                        'sort_order' => $sortOrder,
                        'archived' => false,
                        'notes' => null,
                    ]);
                    $category->save();

                    if (in_array($definition['direction'], ['income', 'expense'], true)) {
                        $planExists = CategoryBudgetPlan::query()
                            ->where('category_id', $category->id)
                            ->whereDate('effective_month', $effectiveMonth)
                            ->exists();

                        if (! $planExists) {
                            CategoryBudgetPlan::query()->create([
                                'category_id' => $category->id,
                                'effective_month' => $effectiveMonth,
                                'planned_amount' => 0,
                                'created_by_user_id' => null,
                            ]);
                        }
                    }

                    if ($definition['rollover']) {
                        $settingExists = CategoryRolloverSetting::query()
                            ->where('category_id', $category->id)
                            ->whereDate('effective_month', $effectiveMonth)
                            ->exists();

                        if (! $settingExists) {
                            CategoryRolloverSetting::query()->create([
                                'category_id' => $category->id,
                                'effective_month' => $effectiveMonth,
                                'enabled' => true,
                                'created_by_user_id' => null,
                            ]);
                        }
                    }
                }
            }

        }
    }
}
