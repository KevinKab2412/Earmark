<?php

use App\Enums\PositionClassification;
use App\Enums\PositionPurpose;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryRolloverSetting;
use App\Models\FinancialPosition;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Valuation;
use App\Services\Goal\GoalService;
use Illuminate\Database\QueryException;

test('a save-up goal reads progress from its linked category balance', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = Category::factory()->create(['household_id' => $household->id]);
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

test('linking a savings goal turns rollover on and a second active goal is rejected', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $month = now()->startOfMonth()->toDateString();

    $this->actingAs($user)->post(route('household.goals.store'), [
        'name' => 'Grocery cushion',
        'type' => 'save_up',
        'target_amount' => 20000,
        'linked_category_id' => $category->id,
    ])->assertRedirect(route('household.goals.index'))->assertSessionHasNoErrors();

    $first = Goal::query()->where('household_id', $household->id)->where('name', 'Grocery cushion')->firstOrFail();

    expect((bool) CategoryRolloverSetting::query()
        ->where('category_id', $category->id)
        ->whereDate('effective_month', $month)
        ->value('enabled'))->toBeTrue();

    $this->actingAs($user)->from(route('household.goals.index'))->post(route('household.goals.store'), [
        'name' => 'Second cushion',
        'type' => 'save_up',
        'target_amount' => 10000,
        'linked_category_id' => $category->id,
    ])->assertSessionHasErrors('linked_category_id');

    expect(Goal::query()->where('linked_category_id', $category->id)->count())->toBe(1)
        ->and($first->refresh()->name)->toBe('Grocery cushion');

    $otherCategory = $household->categories()->where('name', 'Restaurants')->firstOrFail();

    $this->actingAs($user)->post(route('household.goals.store'), [
        'name' => 'Dining cushion',
        'type' => 'save_up',
        'target_amount' => 10000,
        'linked_category_id' => $otherCategory->id,
    ])->assertSessionHasNoErrors();
});

test('an archived savings goal does not block a new active goal on that category', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Vacation')->firstOrFail();

    Goal::factory()->create([
        'household_id' => $household->id,
        'type' => 'save_up',
        'status' => 'archived',
        'linked_category_id' => $category->id,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)->post(route('household.goals.store'), [
        'name' => 'Active vacation',
        'type' => 'save_up',
        'target_amount' => 30000,
        'linked_category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    expect(Goal::query()->where('linked_category_id', $category->id)->count())->toBe(2);
});

test('the database rejects a second active savings goal on the same category', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Emergency Fund')->firstOrFail();

    Goal::factory()->create([
        'household_id' => $household->id,
        'type' => 'save_up',
        'status' => 'active',
        'linked_category_id' => $category->id,
        'created_by_user_id' => $user->id,
    ]);

    expect(fn () => Goal::query()->create([
        'household_id' => $household->id,
        'name' => 'Duplicate fund',
        'type' => 'save_up',
        'status' => 'active',
        'target_amount' => 100,
        'linked_category_id' => $category->id,
        'created_by_user_id' => $user->id,
    ]))->toThrow(QueryException::class);

    expect(Goal::query()->where('linked_category_id', $category->id)->count())->toBe(1);
});

test('a savings goal needs a positive target amount', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Vacation')->firstOrFail();

    $this->actingAs($user)->from(route('household.goals.index'))->post(route('household.goals.store'), [
        'name' => 'Blank vacation goal',
        'type' => 'save_up',
        'target_amount' => 0,
        'linked_category_id' => $category->id,
    ])->assertSessionHasErrors(['target_amount' => 'Enter a target amount greater than zero.']);

    expect(Goal::query()->where('household_id', $household->id)->count())->toBe(0);
});

test('archived goals appear only in the archived view', function () {
    $user = User::factory()->create();
    $householdId = $user->household()->id;
    $activeGoal = Goal::factory()->create([
        'household_id' => $householdId,
        'name' => 'Current trip',
        'status' => 'active',
        'created_by_user_id' => $user->id,
    ]);
    Goal::factory()->create([
        'household_id' => $householdId,
        'name' => 'Past trip',
        'status' => 'archived',
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)->get(route('household.goals.index'))
        ->assertInertia(fn ($page) => $page
            ->where('statusFilter', 'active')
            ->has('goals', 1)
            ->where('goals.0.name', $activeGoal->name));

    $this->actingAs($user)->get(route('household.goals.index', ['status' => 'archived']))
        ->assertInertia(fn ($page) => $page
            ->where('statusFilter', 'archived')
            ->has('goals', 1)
            ->where('goals.0.name', 'Past trip'));
});

test('updating a goal onto a category that already has an active savings goal is rejected', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $first = Goal::factory()->create([
        'household_id' => $household->id,
        'name' => 'First grocery goal',
        'type' => 'save_up',
        'status' => 'active',
        'linked_category_id' => $category->id,
        'created_by_user_id' => $user->id,
    ]);
    $second = Goal::factory()->create([
        'household_id' => $household->id,
        'name' => 'Parked goal',
        'type' => 'save_up',
        'status' => 'archived',
        'linked_category_id' => $category->id,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)->from(route('household.goals.index'))->patch(route('household.goals.update', $second), [
        'name' => 'Parked goal',
        'type' => 'save_up',
        'target_amount' => $second->target_amount,
        'status' => 'active',
        'linked_category_id' => $category->id,
    ])->assertSessionHasErrors('linked_category_id');

    expect($second->refresh()->status)->toBe('archived')
        ->and($first->refresh()->name)->toBe('First grocery goal');

    $this->actingAs($user)->patch(route('household.goals.update', $first), [
        'name' => 'Renamed grocery goal',
        'type' => 'save_up',
        'target_amount' => $first->target_amount,
        'status' => 'active',
        'linked_category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    expect($first->refresh()->name)->toBe('Renamed grocery goal');
});

test('a debt goal reports the liability balance and ignores the payment category', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create([
        'household_id' => $household->id,
        'type' => 'credit_card',
        'starting_balance' => -10000,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'amount' => 3000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    $paymentCategory = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $paymentCategory->budgetPlans()->whereDate('effective_month', now()->startOfMonth()->toDateString())->update([
        'planned_amount' => 999999,
    ]);

    $this->actingAs($user)->post(route('household.goals.store'), [
        'name' => 'Card payoff',
        'type' => 'pay_down',
        'target_amount' => 0,
        'linked_account_id' => $account->id,
        'payment_category_id' => $paymentCategory->id,
        'principal' => 1,
    ])->assertRedirect(route('household.goals.index'))->assertSessionHasNoErrors();

    $goal = Goal::query()->where('household_id', $household->id)->where('name', 'Card payoff')->firstOrFail();

    expect($goal->payment_category_id)->toBe($paymentCategory->id)
        ->and(app(GoalService::class)->principal($goal))->toBe(7000);

    $this->actingAs($user)->get(route('household.goals.index'))
        ->assertInertia(fn ($page) => $page
            ->where('goals.0.name', 'Card payoff')
            ->where('goals.0.principal_formatted', '$70.00')
        );
});

test('a debt goal can use a transfer payment category without treating the payment as spending', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create([
        'household_id' => $household->id,
        'type' => 'credit_card',
        'starting_balance' => -12000,
    ]);
    $paymentCategory = $household->categories()->where('name', 'Credit Card Payment')->firstOrFail();

    $this->actingAs($user)->from(route('household.goals.index'))->post(route('household.goals.store'), [
        'name' => 'Card payoff',
        'type' => 'pay_down',
        'target_amount' => 0,
        'linked_account_id' => $account->id,
        'payment_category_id' => $paymentCategory->id,
    ])->assertRedirect(route('household.goals.index'))->assertSessionHasNoErrors();

    $goal = Goal::query()->where('household_id', $household->id)->where('name', 'Card payoff')->firstOrFail();

    expect($goal->payment_category_id)->toBe($paymentCategory->id)
        ->and($paymentCategory->direction)->toBe('transfer')
        ->and(app(GoalService::class)->principal($goal))->toBe(12000);
});

test('a goal cannot link another household category or account', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $other = User::factory()->create();
    $foreignCategory = $other->household()->categories()->where('name', 'Emergency Fund')->firstOrFail();
    $foreignAccount = Account::factory()->create([
        'household_id' => $other->household()->id,
        'type' => 'credit_card',
        'starting_balance' => -5000,
    ]);

    $this->actingAs($user)->from(route('household.goals.index'))->post(route('household.goals.store'), [
        'name' => 'Stolen savings',
        'type' => 'save_up',
        'target_amount' => 1000,
        'linked_category_id' => $foreignCategory->id,
    ])->assertSessionHasErrors('linked_category_id');

    $this->actingAs($user)->from(route('household.goals.index'))->post(route('household.goals.store'), [
        'name' => 'Stolen debt',
        'type' => 'pay_down',
        'target_amount' => 0,
        'linked_account_id' => $foreignAccount->id,
    ])->assertSessionHasErrors('linked_account_id');

    expect(Goal::query()->where('household_id', $household->id)->whereIn('name', ['Stolen savings', 'Stolen debt'])->count())->toBe(0);
});
