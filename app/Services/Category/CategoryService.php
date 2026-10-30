<?php

namespace App\Services\Category;

use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryGroup;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CategoryService
{
    /**
     * @return Collection<int, Category>
     */
    public function listForHousehold(Household $household): Collection
    {
        return $household->categories()
            ->where('archived', false)
            ->with('group:id,name,sort_order')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, category_group_id: string, direction: string, icon?: ?string, starting_month?: ?string, starting_balance_cents?: int, year?: int|string, month?: int|string}  $data
     */
    public function create(array $data, Household $household): Category
    {
        $year = isset($data['year']) ? (int) $data['year'] : now()->year;
        $month = isset($data['month']) ? (int) $data['month'] : now()->month;
        $startingMonth = isset($data['starting_month'])
            ? CarbonImmutable::createFromFormat('Y-m', $data['starting_month'])->startOfMonth()
            : CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth();
        $category = Category::query()->create([
            'name' => $data['name'],
            'category_group_id' => $data['category_group_id'],
            'starting_month' => $startingMonth->toDateString(),
            'starting_balance' => (int) ($data['starting_balance_cents'] ?? 0),
            'direction' => $data['direction'],
            'icon' => $data['icon'] ?? null,
            'household_id' => $household->id,
        ]);

        if (in_array($category->direction, ['income', 'expense'], true)) {
            CategoryBudgetPlan::query()->firstOrCreate(
                [
                    'category_id' => $category->id,
                    'effective_month' => $startingMonth->toDateString(),
                ],
                ['planned_amount' => 0],
            );
        }

        return $category;
    }

    /**
     * @param  array{name: string}  $data
     */
    public function createGroup(array $data, Household $household): CategoryGroup
    {
        $lastSortOrder = (int) $household->categoryGroups()->max('sort_order');

        return CategoryGroup::query()->create([
            ...$data,
            'household_id' => $household->id,
            'sort_order' => $lastSortOrder + 10,
        ]);
    }
}
