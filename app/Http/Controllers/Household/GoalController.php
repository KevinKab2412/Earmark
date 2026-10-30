<?php

namespace App\Http\Controllers\Household;

use App\Actions\Categories\SetCategoryRollover;
use App\Actions\Goals\CreateGoal;
use App\Actions\Goals\UpdateGoal;
use App\Concerns\ResolvesHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Household\ReorderGoalsRequest;
use App\Http\Requests\Household\StoreGoalRequest;
use App\Http\Requests\Household\UpdateGoalRequest;
use App\Models\Category;
use App\Models\Goal;
use App\Models\User;
use App\Services\Goal\GoalService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    use ResolvesHousehold;

    public function __construct(
        private GoalService $goals,
        private SetCategoryRollover $setCategoryRollover,
    ) {}

    public function index(Request $request): Response
    {
        $household = $this->household($request);

        $goals = Goal::query()
            ->where('household_id', $household->id)
            ->whereIn('status', ['active', 'achieved'])
            ->with(['account:id,name', 'category:id,name', 'paymentCategory:id,name', 'position:id,name'])
            ->orderBy('sort_order')->orderBy('created_at')
            ->get()
            ->map(function (Goal $goal): array {
                $base = [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'type' => $goal->type,
                    'status' => $goal->status,
                    'target_amount' => $goal->target_amount,
                    'target_amount_formatted' => Money::format($goal->target_amount),
                    'target_date' => $goal->target_date?->toDateString(),
                    'apr_bps' => $goal->apr_bps,
                    'required_payment' => $goal->required_payment,
                    'required_payment_formatted' => $goal->required_payment !== null ? Money::format($goal->required_payment) : null,
                    'principal' => $goal->principal,
                    'linked_account_id' => $goal->linked_account_id,
                    'linked_category_id' => $goal->linked_category_id,
                    'payment_category_id' => $goal->payment_category_id,
                    'linked_position_id' => $goal->linked_position_id,
                    'linked_to' => $goal->category?->name ?? $goal->account?->name ?? $goal->position?->name,
                    'payment_category' => $goal->paymentCategory?->name,
                ];

                if ($goal->type === 'pay_down') {
                    $payoff = $this->goals->payoff($goal);
                    $principal = $this->goals->principal($goal);

                    return [
                        ...$base,
                        'principal_formatted' => Money::format($principal),
                        'apr' => $goal->apr_bps !== null ? number_format($goal->apr_bps / 100, 2).'%' : null,
                        'payoff' => [
                            'payable' => $payoff['payable'],
                            'months' => $payoff['months'],
                            'payoff_date' => $payoff['payoff_date'],
                            'total_interest' => $payoff['total_interest'] !== null ? Money::format($payoff['total_interest']) : null,
                        ],
                    ];
                }

                $progress = $this->goals->progress($goal);

                return [
                    ...$base,
                    'progress' => [
                        'current' => Money::format($progress['current']),
                        'remaining' => Money::format($progress['remaining']),
                        'percent' => $progress['percent'],
                    ],
                ];
            });

        return Inertia::render('household/Goals', [
            'goals' => $goals,
            'types' => Goal::TYPES,
            'categories' => $household->categories()->where('archived', false)->whereIn('direction', ['income', 'expense'])->orderBy('name')->get(['id', 'name']),
            'paymentCategories' => $household->categories()->where('archived', false)->where('direction', 'expense')->orderBy('name')->get(['id', 'name']),
            'accounts' => $household->accounts()->where('archived', false)->whereIn('type', ['credit_card', 'mortgage', 'loan'])->orderBy('name')->get(['id', 'name']),
            'positions' => $household->financialPositions()->where('archived', false)->where('classification', 'liability')->orderBy('name')->get(['id', 'name']),
            'defaults' => ['target_date' => now()->addYear()->toDateString()],
        ]);
    }

    public function store(StoreGoalRequest $request, CreateGoal $action): RedirectResponse
    {
        $goal = $action->handle($request->validated(), $request->user(), $this->household($request));
        $this->enableSavingsCategoryRollover($goal, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goal created.')]);

        return to_route('household.goals.index');
    }

    public function update(UpdateGoalRequest $request, Goal $goal, UpdateGoal $action): RedirectResponse
    {
        $action->handle($goal, $request->validated());
        $this->enableSavingsCategoryRollover($goal->refresh(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goal updated.')]);

        return back();
    }

    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        abort_unless($goal->household_id === $this->household($request)->id, 404);

        $goal->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goal removed.')]);

        return back();
    }

    public function reorder(ReorderGoalsRequest $request): RedirectResponse
    {
        $household = $this->household($request);

        foreach ($request->validated('ids') as $position => $id) {
            Goal::query()->where('household_id', $household->id)->whereKey($id)->update(['sort_order' => $position]);
        }

        return back();
    }

    private function enableSavingsCategoryRollover(Goal $goal, User $user): void
    {
        if ($goal->type !== 'save_up' || $goal->linked_category_id === null) {
            return;
        }

        $category = Category::query()->find($goal->linked_category_id);
        if (! $category) {
            return;
        }

        $now = now();
        $this->setCategoryRollover->handle($category, true, $now->year, $now->month, $user);
    }
}
