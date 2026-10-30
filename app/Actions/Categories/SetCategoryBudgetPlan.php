<?php

namespace App\Actions\Categories;

use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use App\Models\User;
use App\Services\CategoryBudgetService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SetCategoryBudgetPlan
{
    public function __construct(private CategoryBudgetService $budget) {}

    public function handle(Category $category, int $amount, int $year, int $month, bool $applyToFutureMonths, User $user): void
    {
        $effectiveMonth = CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth();
        $effectiveDate = $effectiveMonth->toDateString();
        $previousAmount = $this->budget->planForMonth($category, $year, $month);

        DB::transaction(function () use ($category, $amount, $effectiveMonth, $effectiveDate, $previousAmount, $applyToFutureMonths, $user): void {
            $plans = $category->budgetPlans();

            if ($applyToFutureMonths) {
                $plans->whereDate('effective_month', '>=', $effectiveDate)->delete();
            }

            $this->savePlan($category, $effectiveMonth, $amount, $user);

            if ($applyToFutureMonths) {
                return;
            }

            $nextMonth = $effectiveMonth->addMonth();
            $existingNextPlan = $category->budgetPlans()->whereDate('effective_month', $nextMonth->toDateString())->first();

            if ($existingNextPlan === null) {
                CategoryBudgetPlan::query()->create([
                    'category_id' => $category->id,
                    'effective_month' => $nextMonth->toDateString(),
                    'planned_amount' => $previousAmount,
                    'created_by_user_id' => $user->id,
                ]);
            }
        });
    }

    private function savePlan(Category $category, CarbonImmutable $effectiveMonth, int $amount, User $user): void
    {
        $plan = $category->budgetPlans()
            ->whereDate('effective_month', $effectiveMonth->toDateString())
            ->first();

        if ($plan === null) {
            CategoryBudgetPlan::query()->create([
                'category_id' => $category->id,
                'effective_month' => $effectiveMonth->toDateString(),
                'planned_amount' => $amount,
                'created_by_user_id' => $user->id,
            ]);

            return;
        }

        $plan->update([
            'planned_amount' => $amount,
            'created_by_user_id' => $user->id,
        ]);
    }
}
