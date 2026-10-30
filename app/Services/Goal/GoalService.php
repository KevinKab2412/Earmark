<?php

namespace App\Services\Goal;

use App\Models\Goal;
use App\Models\Transaction;
use App\Services\CategoryBudgetService;
use Carbon\CarbonImmutable;

/**
 * Grounds goal progress in canonical balances. A save-up goal's current amount
 * comes from a linked category balance; a debt goal's
 * remaining principal comes from a linked liability position or a stored figure.
 * Nothing here writes to the ledger, and transfers are never treated as
 * spending (task #1254).
 */
class GoalService
{
    public function __construct(private CategoryBudgetService $budget) {}

    public function currentAmount(Goal $goal): int
    {
        if ($goal->type === 'pay_down') {
            return $this->signedLiabilityBalance($goal);
        }

        if ($goal->linked_category_id !== null && $goal->category !== null) {
            $now = CarbonImmutable::now();

            return $this->budget->availableForMonth($goal->category, $now->year, $now->month);
        }

        if ($goal->linked_account_id !== null && $goal->account !== null) {
            return $this->accountBalance($goal);
        }

        if ($goal->linked_position_id !== null && $goal->position !== null) {
            return $this->latestValuationAmount($goal) ?? 0;
        }

        return 0;
    }

    /**
     * @return array{current: int, target: int, remaining: int, percent: int}
     */
    public function progress(Goal $goal): array
    {
        $current = $this->currentAmount($goal);
        $target = $goal->target_amount;
        $percent = $target > 0 ? (int) min(100, max(0, round($current / $target * 100))) : 0;

        return [
            'current' => $current,
            'target' => $target,
            'remaining' => max(0, $target - $current),
            'percent' => $percent,
        ];
    }

    public function principal(Goal $goal): int
    {
        if ($goal->linked_position_id !== null && $goal->position !== null) {
            $amount = $this->latestValuationAmount($goal);

            if ($amount !== null) {
                return abs($amount);
            }
        }

        if ($goal->linked_account_id !== null && $goal->account !== null) {
            return abs($this->accountBalance($goal));
        }

        return abs($goal->principal ?? 0);
    }

    private function signedLiabilityBalance(Goal $goal): int
    {
        if ($goal->linked_position_id !== null && $goal->position !== null) {
            return $this->latestValuationAmount($goal) ?? 0;
        }

        if ($goal->linked_account_id !== null && $goal->account !== null) {
            return $this->accountBalance($goal);
        }

        return $goal->principal ?? 0;
    }

    private function accountBalance(Goal $goal): int
    {
        $movements = (int) Transaction::query()
            ->where('household_id', $goal->household_id)
            ->where('account_id', $goal->account->id)
            ->sum('amount');

        return $goal->account->starting_balance + $movements;
    }

    /**
     * @return array{payable: bool, months: ?int, payoff_date: ?string, total_interest: ?int}
     */
    public function payoff(Goal $goal): array
    {
        $principal = $this->principal($goal);
        $payment = $goal->required_payment ?? 0;
        $monthlyRate = ($goal->apr_bps ?? 0) / 10000 / 12;
        $none = ['payable' => false, 'months' => null, 'payoff_date' => null, 'total_interest' => null];

        if ($principal <= 0 || $payment <= 0) {
            return $none;
        }

        if ($monthlyRate <= 0.0) {
            $months = (int) ceil($principal / $payment);
        } else {
            if ($payment <= $principal * $monthlyRate) {
                return $none; // payment does not even cover interest — never pays off
            }

            $months = (int) ceil(-log(1 - ($principal * $monthlyRate) / $payment) / log(1 + $monthlyRate));
        }

        return [
            'payable' => true,
            'months' => $months,
            'payoff_date' => CarbonImmutable::now()->addMonths($months)->toDateString(),
            'total_interest' => max(0, $payment * $months - $principal),
        ];
    }

    private function latestValuationAmount(Goal $goal): ?int
    {
        return $goal->position?->valuations()
            ->orderByDesc('valued_at')
            ->orderByDesc('id')
            ->value('amount');
    }
}
