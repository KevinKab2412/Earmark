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
use App\Services\CategoryBudgetService;
use App\Services\NetWorth\SnapshotService;
use App\Services\Transaction\TransactionRegisterService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesHousehold;

    public const CARDS = ['budget', 'net_worth', 'goals', 'spending', 'transactions', 'recurring', 'investments'];

    public function __construct(
        private CategoryBudgetService $budget,
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

        return Inertia::render('household/Overview', [
            'availableCards' => self::CARDS,
            'enabledCards' => $this->enabledOverviewCards($household->overview_cards),
            'cards' => [
                'budget' => [
                    'planned' => Money::format((int) $budgetRows->sum('planned_amount')),
                    'available' => Money::format((int) $budgetRows->sum('available')),
                    'over_budget' => $overspent,
                ],
                'recurring' => [
                    'count' => RecurringSchedule::query()->where('household_id', $household->id)->where('status', 'confirmed')->count(),
                ],
                'goals' => [
                    'count' => Goal::query()->where('household_id', $household->id)->where('status', 'active')->count(),
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
                'spending' => $this->spendingCard($budgetRows),
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
     * This month's expense-category activity, largest spent first.
     * Income, transfers, refunds, and categories with no spending are omitted.
     *
     * @param  Collection<int, array{id: string, name: string, direction: string, planned_amount: int, activity: int, carryover: int, opening_balance: int, available: int, rollover_enabled: bool}>  $budgetRows
     * @return array{series: list<array{name: string, spent: string, width: float}>, total: string}
     */
    private function spendingCard(Collection $budgetRows): array
    {
        $spentRows = $budgetRows
            ->filter(fn (array $row): bool => $row['direction'] === 'expense' && $row['activity'] < 0)
            ->sortByDesc(fn (array $row): int => abs((int) $row['activity']))
            ->values();

        $largest = abs((int) ($spentRows->first()['activity'] ?? 0));

        $series = $spentRows->map(function (array $row) use ($largest): array {
            $spent = abs((int) $row['activity']);

            return [
                'name' => $row['name'],
                'spent' => Money::format($spent),
                'width' => $largest === 0 ? 0.0 : round(($spent / $largest) * 100, 2),
            ];
        })->all();

        $total = (int) $spentRows->sum(fn (array $row): int => abs((int) $row['activity']));

        return [
            'series' => $series,
            'total' => Money::format($total),
        ];
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
