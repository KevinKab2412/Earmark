<?php

namespace App\Http\Controllers\Household;

use App\Concerns\ResolvesHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\UpdateOverviewCardsRequest;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Holding;
use App\Models\RecurringSchedule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\CategoryBudgetService;
use App\Services\Goal\GoalService;
use App\Services\NetWorth\SnapshotService;
use App\Services\Transaction\TransactionRegisterService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesHousehold;

    public const CARDS = ['budget', 'spending', 'transactions', 'recurring', 'net_worth', 'investments', 'goals'];

    public function __construct(
        private CategoryBudgetService $budget,
        private GoalService $goals,
        private SnapshotService $snapshot,
        private TransactionRegisterService $transactionRegister,
    ) {}

    public function show(Request $request): Response
    {
        $household = $this->household($request);
        $year = (int) now()->year;
        $month = (int) now()->month;
        $budgetCategories = $household->categories()
            ->where('archived', false)
            ->whereHas('group', fn ($query) => $query->whereIn('direction', ['income', 'expense']))
            ->with('group:id,direction')
            ->get();

        $budgetRows = $budgetCategories->map(function (Category $category) use ($year, $month): array {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'direction' => $category->direction,
                ...$this->budget->summaryForMonth($category, $year, $month),
            ];
        });

        $overspent = $budgetRows
            ->filter(fn (array $row): bool => $row['available'] < 0)
            ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'available' => Money::format($row['available'])])
            ->values();

        $snapshot = $this->snapshot->forHousehold($household);
        $plan = $household->plans()->latest()->first();
        $recentTransactions = $this->transactionRegister
            ->query($household, [])
            ->limit(5)
            ->get();
        $activeGoals = Goal::query()
            ->where('household_id', $household->id)
            ->where('status', 'active')
            ->with(['account:id,name,starting_balance', 'category:id,name', 'position:id,name'])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->limit(4)
            ->get();

        return Inertia::render('household/Overview', [
            'availableCards' => self::CARDS,
            'enabledCards' => $this->enabledOverviewCards($household->overview_cards),
            'cards' => [
                'budget' => $this->budgetCard($budgetRows, $overspent),
                'recurring' => [
                    'count' => RecurringSchedule::query()->where('household_id', $household->id)->where('status', 'confirmed')->count(),
                ],
                'goals' => [
                    'count' => Goal::query()->where('household_id', $household->id)->where('status', 'active')->count(),
                    'items' => $this->goalItems($activeGoals, $budgetRows),
                ],
                'net_worth' => [
                    'investable' => $snapshot['investable'],
                    'total' => $snapshot['total_net_worth'],
                    'targetGap' => $plan ? Money::format($plan->target_cents - $snapshot['investable_cents']) : null,
                    'freshness' => $snapshot['freshness'],
                    'hasPositions' => $snapshot['has_positions'],
                ],
                'investments' => [
                    'total' => Money::format((int) Holding::query()->where('household_id', $household->id)->sum('market_value_cents')),
                    'count' => Holding::query()->where('household_id', $household->id)->count(),
                ],
                'spending' => $this->spendingCard(
                    $household->id,
                    $budgetCategories->where('direction', 'expense')->pluck('id')->all(),
                ),
                'transactions' => [
                    'items' => $recentTransactions->map(fn (Transaction $transaction): array => [
                        'id' => $transaction->id,
                        'date' => $transaction->date->format('M j'),
                        'payee' => $transaction->payee,
                        'account' => $transaction->account->name,
                        'category' => $transaction->is_split
                            ? __('Split transaction')
                            : ($transaction->category?->name ?? __('Uncategorized')),
                        'category_icon' => $transaction->category?->icon,
                        'amount' => Money::format($transaction->amount),
                        'is_income' => $transaction->amount > 0,
                    ])->values()->all(),
                ],
            ],
        ]);
    }

    /**
     * @param  Collection<int, array{id: string, name: string, direction: string, planned_amount: int, activity: int, carryover: int, opening_balance: int, available: int, rollover_enabled: bool}>  $budgetRows
     * @param  Collection<int, array{id: string, name: string, available: string}>  $overspent
     * @return array{planned: string, available: string, over_budget: list<array{id: string, name: string, available: string}>, groups: list<array{direction: string, label: string, planned: string, activity: string, remaining: string, progress: int, over_budget: bool}>}
     */
    private function budgetCard(Collection $budgetRows, Collection $overspent): array
    {
        $groups = collect([
            ['direction' => 'income', 'label' => 'Income'],
            ['direction' => 'expense', 'label' => 'Expenses'],
        ])->map(function (array $group) use ($budgetRows): array {
            $rows = $budgetRows->where('direction', $group['direction']);
            $planned = (int) $rows->sum('planned_amount');
            $activity = (int) $rows->sum('activity');
            $available = (int) $rows->sum('available');
            $activityAmount = $group['direction'] === 'income'
                ? max(0, $activity)
                : max(0, -$activity);
            $remaining = $group['direction'] === 'income' ? $planned - $activity : $available;

            return [
                ...$group,
                'planned' => Money::format($planned),
                'activity' => Money::format($activityAmount),
                'remaining' => Money::format($remaining),
                'progress' => $planned > 0 ? (int) min(100, round($activityAmount / $planned * 100)) : 0,
                'over_budget' => $group['direction'] === 'expense' && $remaining < 0,
            ];
        })->all();

        return [
            'planned' => Money::format((int) $budgetRows->sum('planned_amount')),
            'available' => Money::format((int) $budgetRows->sum('available')),
            'over_budget' => $overspent->all(),
            'groups' => $groups,
        ];
    }

    /**
     * @param  Collection<int, Goal>  $activeGoals
     * @param  Collection<int, array{id: string, name: string, direction: string, planned_amount: int, activity: int, carryover: int, opening_balance: int, available: int, rollover_enabled: bool}>  $budgetRows
     * @return list<array{id: string, name: string, type: string, linked_to: ?string, current: string, target: string, progress: int}>
     */
    private function goalItems(Collection $activeGoals, Collection $budgetRows): array
    {
        $budgetsByCategory = $budgetRows->keyBy('id');

        return $activeGoals->map(function (Goal $goal) use ($budgetsByCategory): array {
            $current = $goal->type === 'pay_down'
                ? $this->goals->principal($goal)
                : (int) ($budgetsByCategory->get($goal->linked_category_id)['available'] ?? 0);
            $target = (int) $goal->target_amount;

            return [
                'id' => $goal->id,
                'name' => $goal->name,
                'type' => $goal->type,
                'linked_to' => $goal->category?->name ?? $goal->account?->name ?? $goal->position?->name,
                'current' => Money::format($current),
                'target' => Money::format($target),
                'progress' => $goal->type === 'save_up' && $target > 0
                    ? (int) min(100, max(0, round($current / $target * 100)))
                    : 0,
            ];
        })->all();
    }

    /**
     * @param  list<string>  $expenseCategoryIds
     * @return array{current_month: string, previous_month: string, current_total: string, previous_total: string, current_series: list<array{day: int, amount: int}>, previous_series: list<array{day: int, amount: int}>}
     */
    private function spendingCard(string $householdId, array $expenseCategoryIds): array
    {
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $previousMonth = $currentMonth->subMonth();
        $dailyAmounts = [];

        if ($expenseCategoryIds !== []) {
            $transactions = Transaction::query()
                ->where('household_id', $householdId)
                ->whereNull('transfer_pair_id')
                ->where('excluded_from_reports', false)
                ->where('is_split', false)
                ->where('amount', '<', 0)
                ->whereIn('category_id', $expenseCategoryIds)
                ->whereBetween('date', [$previousMonth->toDateString(), $currentMonth->endOfMonth()->toDateString()])
                ->selectRaw('DATE(transactions.date) as day, SUM(transactions.amount) as amount')
                ->groupByRaw('DATE(transactions.date)')
                ->get();

            $splits = TransactionSplit::query()
                ->join('transactions', 'transactions.id', '=', 'transaction_splits.transaction_id')
                ->where('transactions.household_id', $householdId)
                ->whereNull('transactions.transfer_pair_id')
                ->where('transactions.excluded_from_reports', false)
                ->where('transaction_splits.amount', '<', 0)
                ->whereIn('transaction_splits.category_id', $expenseCategoryIds)
                ->whereBetween('transactions.date', [$previousMonth->toDateString(), $currentMonth->endOfMonth()->toDateString()])
                ->selectRaw('DATE(transactions.date) as day, SUM(transaction_splits.amount) as amount')
                ->groupByRaw('DATE(transactions.date)')
                ->get();

            foreach ($transactions->concat($splits) as $row) {
                $day = CarbonImmutable::parse($row->day)->toDateString();
                $dailyAmounts[$day] = ($dailyAmounts[$day] ?? 0) + (int) $row->amount;
            }
        }

        $currentSeries = $this->spendingSeries($currentMonth, min(now()->day, $currentMonth->daysInMonth), $dailyAmounts);
        $previousSeries = $this->spendingSeries($previousMonth, $previousMonth->daysInMonth, $dailyAmounts);

        return [
            'current_month' => $currentMonth->format('F Y'),
            'previous_month' => $previousMonth->format('F Y'),
            'current_total' => Money::format((int) ($currentSeries[array_key_last($currentSeries)]['amount'] ?? 0)),
            'previous_total' => Money::format((int) ($previousSeries[array_key_last($previousSeries)]['amount'] ?? 0)),
            'current_series' => $currentSeries,
            'previous_series' => $previousSeries,
        ];
    }

    /**
     * @param  array<string, int>  $dailyAmounts
     * @return list<array{day: int, amount: int}>
     */
    private function spendingSeries(CarbonImmutable $month, int $days, array $dailyAmounts): array
    {
        $running = 0;
        $series = [];

        for ($day = 1; $day <= $days; $day++) {
            $date = $month->addDays($day - 1)->toDateString();
            $running += $dailyAmounts[$date] ?? 0;
            $series[] = ['day' => $day, 'amount' => max(0, -$running)];
        }

        return $series;
    }

    public function updateCards(UpdateOverviewCardsRequest $request): RedirectResponse
    {
        $household = $this->household($request);
        $household->update(['overview_cards' => $request->validated('cards')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Overview updated.')]);

        return back();
    }

    /**
     * Saved lists may still name cards this overview no longer offers.
     * Valid keys stay in their stored order. When none remain, the current
     * default set is shown and the stored list is left unchanged.
     *
     * @return list<string>
     */
    private function enabledOverviewCards(mixed $stored): array
    {
        if (! is_array($stored)) {
            return self::CARDS;
        }

        $enabled = [];

        foreach ($stored as $key) {
            if (is_string($key) && in_array($key, self::CARDS, true)) {
                $enabled[] = $key;
            }
        }

        if ($enabled === []) {
            return self::CARDS;
        }

        return $enabled;
    }
}
