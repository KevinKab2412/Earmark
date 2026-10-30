<?php

namespace App\Http\Controllers\Household;

use App\Concerns\ResolvesHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\UpdateOverviewCardsRequest;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Holding;
use App\Models\RecurringSchedule;
use App\Models\Scenario;
use App\Models\TransactionSplit;
use App\Services\CategoryBudgetService;
use App\Services\NetWorth\SnapshotService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesHousehold;

    public const CARDS = ['budget', 'top_categories', 'review_queue', 'recurring', 'goals', 'net_worth', 'investments', 'scenarios'];

    public function __construct(
        private CategoryBudgetService $budget,
        private SnapshotService $snapshot,
    ) {}

    public function show(Request $request): Response
    {
        $household = $this->household($request);
        $year = (int) now()->year;
        $month = (int) now()->month;
        $budgetCategories = $household->categories()
            ->where('archived', false)
            ->whereIn('direction', ['income', 'expense'])
            ->get();

        $budgetRows = $budgetCategories->map(function (Category $category) use ($year, $month): array {
            return [
                'id' => $category->id,
                'name' => $category->name,
                ...$this->budget->summaryForMonth($category, $year, $month),
            ];
        });

        $overspent = $budgetRows
            ->filter(fn (array $row): bool => $row['available'] < 0)
            ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'available' => Money::format($row['available'])])
            ->values();

        $snapshot = $this->snapshot->forHousehold($household);
        $plan = $household->plans()->latest()->first();

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $directSpend = $household->transactions()
            ->whereNull('transfer_pair_id')
            ->where('is_split', false)
            ->whereNotNull('category_id')
            ->whereHas('category', fn ($query) => $query->where('direction', 'expense'))
            ->where('amount', '<', 0)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->get(['category_id', 'amount']);

        $splitSpend = TransactionSplit::query()
            ->whereNotNull('category_id')
            ->where('amount', '<', 0)
            ->whereHas('transaction', function ($query) use ($household, $monthStart, $monthEnd): void {
                $query->where('household_id', $household->id)
                    ->whereNull('transfer_pair_id')
                    ->whereBetween('date', [$monthStart, $monthEnd]);
            })
            ->whereHas('category', fn ($query) => $query->where('direction', 'expense'))
            ->get(['category_id', 'amount']);

        $categorySpend = $directSpend->concat($splitSpend)
            ->groupBy('category_id')
            ->map(fn ($rows, string $categoryId): object => (object) [
                'category_id' => $categoryId,
                'total_cents' => (int) $rows->sum('amount'),
            ])
            ->sortBy('total_cents')
            ->take(6)
            ->values();

        $categoryNames = Category::query()
            ->whereIn('id', $categorySpend->pluck('category_id'))
            ->where('direction', 'expense')
            ->get(['id', 'name', 'direction'])
            ->keyBy('id');

        $maxSpend = abs((int) ($categorySpend->min('total_cents') ?? 0)) ?: 1;
        $monthSpendCents = (int) $household->transactions()
            ->whereNull('transfer_pair_id')
            ->where('is_split', false)
            ->where('amount', '<', 0)
            ->where(function ($query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('direction', 'expense'));
            })
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('amount') + (int) TransactionSplit::query()
            ->where('amount', '<', 0)
            ->whereHas('transaction', function ($query) use ($household, $monthStart, $monthEnd): void {
                $query->where('household_id', $household->id)
                    ->whereNull('transfer_pair_id')
                    ->whereBetween('date', [$monthStart, $monthEnd]);
            })
            ->where(function ($query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('direction', 'expense'));
            })
            ->sum('amount');

        $topCategories = $categorySpend->map(function ($row) use ($categoryNames, $maxSpend): array {
            $spent = abs((int) $row->total_cents);

            return [
                'name' => $categoryNames[$row->category_id]?->name ?? __('Uncategorized'),
                'type' => $categoryNames[$row->category_id]?->direction,
                'spent' => Money::format($spent),
                'ratio' => round($spent / $maxSpend, 4),
            ];
        })->values();

        return Inertia::render('household/Overview', [
            'availableCards' => self::CARDS,
            'enabledCards' => $household->overview_cards ?? self::CARDS,
            'cards' => [
                'budget' => [
                    'planned' => Money::format((int) $budgetRows->sum('planned_amount')),
                    'available' => Money::format((int) $budgetRows->sum('available')),
                    'over_budget' => $overspent,
                ],
                'top_categories' => [
                    'items' => $topCategories,
                    'total' => Money::format(abs($monthSpendCents)),
                ],
                'review_queue' => [
                    'count' => $household->transactions()->whereNull('transfer_pair_id')->where('reviewed', false)->count(),
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
                'scenarios' => [
                    'count' => Scenario::query()->where('household_id', $household->id)->count(),
                ],
            ],
        ]);
    }

    public function updateCards(UpdateOverviewCardsRequest $request): RedirectResponse
    {
        $household = $this->household($request);
        $household->update(['overview_cards' => $request->validated('cards')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Overview updated.')]);

        return back();
    }
}
