<?php

use App\Enums\PositionClassification;
use App\Enums\PositionPurpose;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use App\Models\FinancialPosition;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Valuation;
use App\Services\Goal\GoalService;

test('a save-up goal reads progress from its linked category balance', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = Category::factory()->create(['household_id' => $household->id, 'direction' => 'expense']);
    CategoryBudgetPlan::query()->create([
        'category_id' => $category->id,
        'effective_month' => now()->startOfMonth()->toDateString(),
        'planned_amount' => 5000,
    ]);

    $goal = Goal::factory()->create([
        'household_id' => $household->id,
        'type' => 'save_up',
        'target_amount' => 20000,
        'linked_category_id' => $category->id,
    ]);

    $progress = app(GoalService::class)->progress($goal);

    expect($progress['current'])->toBe(5000)
        ->and($progress['remaining'])->toBe(15000)
        ->and($progress['percent'])->toBe(25);
});

test('a debt goal reads principal from its linked credit-card balance', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id, 'type' => 'credit_card', 'starting_balance' => -10000]);
    Transaction::factory()->create([
        'household_id' => $household->id, 'account_id' => $account->id, 'amount' => 3000,
        'date' => now()->toDateString(), 'created_by_user_id' => $user->id,
    ]);

    $goal = Goal::factory()->create(['household_id' => $household->id, 'type' => 'pay_down', 'linked_account_id' => $account->id]);

    expect(app(GoalService::class)->principal($goal))->toBe(7000);
});

test('a future-home goal links to a home-purchase position balance', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $position = FinancialPosition::factory()->create([
        'household_id' => $household->id,
        'classification' => PositionClassification::Asset,
        'purpose' => PositionPurpose::HomePurchase,
    ]);
    Valuation::factory()->create(['financial_position_id' => $position->id, 'amount' => 45000, 'valued_at' => '2026-01-01']);

    $goal = Goal::factory()->create(['household_id' => $household->id, 'target_amount' => 100000, 'linked_position_id' => $position->id]);

    expect(app(GoalService::class)->currentAmount($goal))->toBe(45000);
});

test('a debt goal projects a payoff from principal, APR, and payment', function () {
    $user = User::factory()->create();
    $goal = Goal::factory()->create([
        'household_id' => $user->household()->id,
        'type' => 'pay_down',
        'target_amount' => 0,
        'principal' => 1000000,
        'apr_bps' => 500,
        'required_payment' => 50000,
    ]);

    $payoff = app(GoalService::class)->payoff($goal);

    expect($payoff['payable'])->toBeTrue()
        ->and($payoff['months'])->toBeGreaterThan(19)
        ->and($payoff['months'])->toBeLessThan(23)
        ->and($payoff['total_interest'])->toBeGreaterThan(0);
});

test('a debt goal whose payment cannot cover interest never pays off', function () {
    $user = User::factory()->create();
    $goal = Goal::factory()->create([
        'household_id' => $user->household()->id,
        'type' => 'pay_down',
        'principal' => 1000000,
        'apr_bps' => 5000,
        'required_payment' => 1000,
    ]);

    expect(app(GoalService::class)->payoff($goal)['payable'])->toBeFalse();
});

test('progress is zero when nothing is linked', function () {
    $user = User::factory()->create();
    $goal = Goal::factory()->create(['household_id' => $user->household()->id, 'target_amount' => 10000]);

    expect(app(GoalService::class)->currentAmount($goal))->toBe(0);
});

test('a goal can be created and the index renders progress', function () {
    $user = User::factory()->create();
    $category = $user->household()->categories()->where('name', 'Emergency Fund')->firstOrFail();

    $this->actingAs($user)->post(route('household.goals.store'), [
        'name' => 'Emergency fund', 'type' => 'save_up', 'target_amount' => 500000,
        'linked_category_id' => $category->id,
    ])->assertRedirect(route('household.goals.index'))->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('household.goals.index'))
        ->assertInertia(fn ($page) => $page->component('household/Goals')->has('goals', 1));
});

test('goals can be reordered for payoff sequence', function () {
    $user = User::factory()->create();
    $a = Goal::factory()->create(['household_id' => $user->household()->id, 'sort_order' => 0]);
    $b = Goal::factory()->create(['household_id' => $user->household()->id, 'sort_order' => 1]);

    $this->actingAs($user)->post(route('household.goals.reorder'), ['ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();

    expect($a->refresh()->sort_order)->toBe(1)->and($b->refresh()->sort_order)->toBe(0);
});

test('a user cannot update another household goal', function () {
    $user = User::factory()->create();
    $other = Goal::factory()->create();

    $this->actingAs($user)->patch(route('household.goals.update', $other), [
        'name' => 'x', 'type' => 'save_up', 'target_amount' => 1,
    ])->assertForbidden();
});
