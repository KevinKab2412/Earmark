<?php

namespace App\Http\Controllers\Household;

use App\Actions\Categories\CreateCategory;
use App\Actions\Categories\UpdateCategory;
use App\Concerns\ResolvesHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\StoreCategoryRequest;
use App\Http\Requests\Household\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use ResolvesHousehold;

    public function index(Request $request): Response
    {
        $groups = $this->household($request)->categoryGroups()
            ->where('archived', false)
            ->with([
                'categories' => function ($query): void {
                    $query->where('archived', false)
                        ->orderBy('sort_order')
                        ->orderBy('name');
                },
                'categories.group:id,direction',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CategoryGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'direction' => $group->direction,
                'categories' => $group->categories->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'icon' => $category->icon,
                    'kind' => $category->kind,
                    'budgetable' => $category->isBudgetable(),
                    'starting_month' => $category->starting_month?->format('Y-m'),
                    'starting_balance' => Money::format($category->starting_balance),
                ])->values()->all(),
            ])->values();

        return Inertia::render('household/Categories', [
            'groups' => $groups,
        ]);
    }

    /**
     * Create a category and return to the caller's page. Unlike the Plan page's
     * category action (which redirects into the plan), this lets any screen — e.g.
     * the import review — create a category inline without navigating away.
     */
    public function store(StoreCategoryRequest $request, CreateCategory $action): RedirectResponse
    {
        $action->handle($request->validated(), $this->household($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return back();
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $action): RedirectResponse
    {
        $action->handle($request->validated(), $category, $this->household($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category updated.')]);

        return back();
    }
}
