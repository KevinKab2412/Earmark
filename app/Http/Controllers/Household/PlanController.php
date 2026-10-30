<?php

namespace App\Http\Controllers\Household;

use App\Actions\Categories\CreateCategory;
use App\Actions\Categories\CreateCategoryGroup;
use App\Actions\Categories\MoveBudgetFunds;
use App\Actions\Categories\SetCategoryBudgetPlan;
use App\Actions\Categories\SetCategoryRollover;
use App\Concerns\ResolvesHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\MoveCategoryBudgetRequest;
use App\Http\Requests\Household\SetCategoryBudgetPlanRequest;
use App\Http\Requests\Household\SetCategoryRolloverRequest;
use App\Http\Requests\Household\StoreCategoryGroupRequest;
use App\Http\Requests\Household\StoreCategoryRequest;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Services\CategoryBudgetService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    use ResolvesHousehold;

    public function __construct(private CategoryBudgetService $budget) {}

    public function index(Request $request): Response
    {
        $household = $this->household($request);
        $cursor = $this->cursor($request);
        $groups = $household->categoryGroups()
            ->where('archived', false)
            ->with(['categories' => function ($query): void {
                $query->where('archived', false)->orderBy('sort_order')->orderBy('name');
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $planGroups = $groups->map(function (CategoryGroup $group) use ($cursor): array {
            $categories = $group->categories->map(function (Category $category) use ($cursor): array {
                $summary = $this->budget->summaryForMonth($category, $cursor->year, $cursor->month);

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'direction' => $category->direction,
                    'budgetable' => in_array($category->direction, ['income', 'expense'], true),
                    'icon' => $category->icon,
                    'planned' => Money::format($summary['planned_amount']),
                    'planned_cents' => $summary['planned_amount'],
                    'activity' => Money::format($summary['activity']),
                    'activity_cents' => $summary['activity'],
                    'carryover' => Money::format($summary['carryover']),
                    'carryover_cents' => $summary['carryover'],
                    'opening_balance' => Money::format($summary['opening_balance']),
                    'opening_balance_cents' => $summary['opening_balance'],
                    'available' => Money::format($summary['available']),
                    'available_cents' => $summary['available'],
                    'rollover_enabled' => $summary['rollover_enabled'],
                ];
            })->values();

            return [
                'id' => $group->id,
                'name' => $group->name,
                'categories' => $categories->all(),
                'planned_cents' => $categories->sum('planned_cents'),
                'activity_cents' => $categories->sum('activity_cents'),
                'available_cents' => $categories->sum('available_cents'),
            ];
        })->values();

        $categories = $planGroups->flatMap(fn (array $group): array => $group['categories'])->values();
        $budgetCategories = $categories->where('budgetable', true)->values();
        $previous = $cursor->subMonth();
        $next = $cursor->addMonth();

        return Inertia::render('household/Plan', [
            'year' => $cursor->year,
            'month' => $cursor->month,
            'monthLabel' => $cursor->format('F Y'),
            'groups' => $planGroups,
            'categories' => $categories,
            'budgetCategories' => $budgetCategories,
            'totals' => [
                'planned' => Money::format($categories->sum('planned_cents')),
                'planned_cents' => $categories->sum('planned_cents'),
                'activity' => Money::format($categories->sum('activity_cents')),
                'available' => Money::format($categories->sum('available_cents')),
            ],
            'previous' => ['year' => $previous->year, 'month' => $previous->month],
            'next' => ['year' => $next->year, 'month' => $next->month],
            'groupsForSelect' => $household->categoryGroups()
                ->where('archived', false)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
            'defaults' => ['apply_to_future_months' => true],
        ]);
    }

    public function storeCategory(StoreCategoryRequest $request, CreateCategory $action): RedirectResponse
    {
        $action->handle($request->validated(), $this->household($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('household.plan.index', $request->only(['year', 'month']));
    }

    public function storeGroup(StoreCategoryGroupRequest $request, CreateCategoryGroup $action): RedirectResponse
    {
        $action->handle($request->validated(), $this->household($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created.')]);

        return to_route('household.plan.index', $request->only(['year', 'month']));
    }

    public function setCategoryBudget(
        SetCategoryBudgetPlanRequest $request,
        Category $category,
        SetCategoryBudgetPlan $action,
    ): RedirectResponse {
        $data = $request->validated();
        $action->handle(
            $category,
            (int) $data['amount_cents'],
            (int) $data['year'],
            (int) $data['month'],
            (bool) $data['apply_to_future_months'],
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Budget updated.')]);

        return to_route('household.plan.index', $request->only(['year', 'month']));
    }

    public function setCategoryRollover(
        SetCategoryRolloverRequest $request,
        Category $category,
        SetCategoryRollover $action,
    ): RedirectResponse {
        $data = $request->validated();
        $action->handle($category, (bool) $data['enabled'], (int) $data['year'], (int) $data['month'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rollover updated.')]);

        return to_route('household.plan.index', $request->only(['year', 'month']));
    }

    public function move(MoveCategoryBudgetRequest $request, MoveBudgetFunds $action): RedirectResponse
    {
        $action->handle($request->validated(), $request->user(), $this->household($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Funds moved between categories.')]);

        return to_route('household.plan.index', $request->only(['year', 'month']));
    }

    private function cursor(Request $request): CarbonImmutable
    {
        $year = (int) $request->integer('year', now()->year);
        $month = (int) $request->integer('month', now()->month);

        abort_unless($month >= 1 && $month <= 12 && $year >= 2000 && $year <= 2100, 404);

        return CarbonImmutable::createFromDate($year, $month, 1);
    }
}
