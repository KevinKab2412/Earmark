<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryBudgetMove;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryRolloverSetting;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Carbon\CarbonImmutable;

class CategoryBudgetService
{
    /**
     * @return array{planned_amount: int, activity: int, carryover: int, opening_balance: int, available: int, rollover_enabled: bool}
     */
    public function summaryForMonth(Category $category, int $year, int $month): array
    {
        if (! in_array($category->direction, ['income', 'expense'], true)) {
            return [
                'planned_amount' => 0,
                'activity' => 0,
                'carryover' => 0,
                'opening_balance' => 0,
                'available' => 0,
                'rollover_enabled' => false,
            ];
        }

        $targetMonth = CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth();
        $startingMonth = $category->starting_month
            ? CarbonImmutable::parse($category->starting_month)->startOfMonth()
            : CarbonImmutable::parse($category->created_at)->startOfMonth();

        if ($targetMonth->lt($startingMonth)) {
            return [
                'planned_amount' => 0,
                'activity' => 0,
                'carryover' => 0,
                'opening_balance' => 0,
                'available' => 0,
                'rollover_enabled' => false,
            ];
        }

        $plans = CategoryBudgetPlan::query()
            ->where('category_id', $category->id)
            ->whereDate('effective_month', '<=', $targetMonth->toDateString())
            ->orderBy('effective_month')
            ->get(['effective_month', 'planned_amount']);

        $rolloverSettings = CategoryRolloverSetting::query()
            ->where('category_id', $category->id)
            ->whereDate('effective_month', '<=', $targetMonth->toDateString())
            ->orderBy('effective_month')
            ->get(['effective_month', 'enabled']);

        $startDate = $startingMonth->toDateString();
        $endDate = $targetMonth->endOfMonth()->toDateString();
        $monthlyActivity = [];

        Transaction::query()
            ->where('category_id', $category->id)
            ->where('is_split', false)
            ->whereNull('transfer_pair_id')
            ->whereBetween('date', [$startDate, $endDate])
            ->get(['date', 'amount'])
            ->each(function (Transaction $transaction) use (&$monthlyActivity): void {
                $monthKey = CarbonImmutable::parse($transaction->date)->format('Y-m');
                $monthlyActivity[$monthKey] = ($monthlyActivity[$monthKey] ?? 0) + (int) $transaction->amount;
            });

        TransactionSplit::query()
            ->where('category_id', $category->id)
            ->whereHas('transaction', function ($query) use ($startDate, $endDate): void {
                $query->whereNull('transfer_pair_id')
                    ->whereBetween('date', [$startDate, $endDate]);
            })
            ->with('transaction:id,date')
            ->get(['transaction_id', 'amount'])
            ->each(function (TransactionSplit $split) use (&$monthlyActivity): void {
                if ($split->transaction === null) {
                    return;
                }

                $monthKey = CarbonImmutable::parse($split->transaction->date)->format('Y-m');
                $monthlyActivity[$monthKey] = ($monthlyActivity[$monthKey] ?? 0) + (int) $split->amount;
            });

        $monthlyMovesIn = [];
        $monthlyMovesOut = [];
        CategoryBudgetMove::query()
            ->where('household_id', $category->household_id)
            ->whereDate('effective_month', '>=', $startDate)
            ->whereDate('effective_month', '<=', $targetMonth->toDateString())
            ->where(function ($query) use ($category): void {
                $query->where('to_category_id', $category->id)
                    ->orWhere('from_category_id', $category->id);
            })
            ->get(['effective_month', 'from_category_id', 'to_category_id', 'amount'])
            ->each(function (CategoryBudgetMove $move) use ($category, &$monthlyMovesIn, &$monthlyMovesOut): void {
                $monthKey = CarbonImmutable::parse($move->effective_month)->format('Y-m');

                if ($move->to_category_id !== $move->from_category_id) {
                    if ($move->to_category_id === $category->id) {
                        $monthlyMovesIn[$monthKey] = ($monthlyMovesIn[$monthKey] ?? 0) + (int) $move->amount;
                    }
                    if ($move->from_category_id === $category->id) {
                        $monthlyMovesOut[$monthKey] = ($monthlyMovesOut[$monthKey] ?? 0) + (int) $move->amount;
                    }
                }
            });

        $planIndex = 0;
        $rolloverIndex = 0;
        $plannedAmount = 0;
        $rolloverEnabled = false;

        while (isset($plans[$planIndex]) && $plans[$planIndex]->effective_month->lt($startingMonth)) {
            $plannedAmount = (int) $plans[$planIndex]->planned_amount;
            $planIndex++;
        }

        while (isset($rolloverSettings[$rolloverIndex]) && $rolloverSettings[$rolloverIndex]->effective_month->lt($startingMonth)) {
            $rolloverEnabled = (bool) $rolloverSettings[$rolloverIndex]->enabled;
            $rolloverIndex++;
        }

        $previousAvailable = 0;
        $result = [
            'planned_amount' => 0,
            'activity' => 0,
            'carryover' => 0,
            'opening_balance' => 0,
            'available' => 0,
            'rollover_enabled' => false,
        ];

        for ($cursor = $startingMonth; $cursor->lte($targetMonth); $cursor = $cursor->addMonth()) {
            while (isset($plans[$planIndex]) && $plans[$planIndex]->effective_month->lte($cursor)) {
                $plannedAmount = (int) $plans[$planIndex]->planned_amount;
                $planIndex++;
            }

            while (isset($rolloverSettings[$rolloverIndex]) && $rolloverSettings[$rolloverIndex]->effective_month->lte($cursor)) {
                $rolloverEnabled = (bool) $rolloverSettings[$rolloverIndex]->enabled;
                $rolloverIndex++;
            }

            $monthKey = $cursor->format('Y-m');
            $carryover = $rolloverEnabled ? $previousAvailable : 0;
            $openingBalance = $cursor->equalTo($startingMonth) ? (int) $category->starting_balance : 0;
            $activity = (int) ($monthlyActivity[$monthKey] ?? 0);
            $available = $openingBalance
                + $carryover
                + $plannedAmount
                + (int) ($monthlyMovesIn[$monthKey] ?? 0)
                - (int) ($monthlyMovesOut[$monthKey] ?? 0)
                + $activity;

            if ($cursor->equalTo($targetMonth)) {
                $result = [
                    'planned_amount' => $plannedAmount,
                    'activity' => $activity,
                    'carryover' => $carryover,
                    'opening_balance' => $openingBalance,
                    'available' => $available,
                    'rollover_enabled' => $rolloverEnabled,
                ];
            }

            $previousAvailable = $available;
        }

        return $result;
    }

    public function planForMonth(Category $category, int $year, int $month): int
    {
        return $this->summaryForMonth($category, $year, $month)['planned_amount'];
    }

    public function availableForMonth(Category $category, int $year, int $month): int
    {
        return $this->summaryForMonth($category, $year, $month)['available'];
    }
}
