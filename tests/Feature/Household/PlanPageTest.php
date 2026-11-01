<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryBudgetService;
use Inertia\Testing\AssertableInertia as Assert;

test('plan page renders category groups for the selected month', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('household.plan.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('household/Plan')
            ->where('monthLabel', now()->format('F Y'))
            ->has('groups')
            ->has('categories')
            ->has('groupsForSelect')
        );
});

test('plan rows expose planned activity available and rollover by category', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $category->budgetPlans()->whereDate('effective_month', now()->startOfMonth()->toDateString())->firstOrFail()
        ->update(['planned_amount' => 10000]);

    $this->actingAs($user)
        ->get(route('household.plan.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('household/Plan')
            ->has('groups')
            ->where('categories', fn ($categories): bool => collect($categories)->contains(fn (array $row): bool => $row['name'] === 'Groceries'
                && $row['planned_cents'] === 10000
                && $row['available_cents'] === 10000
                && array_key_exists('rollover_enabled', $row)
            ))
        );
});

test('a budget change can be limited to one month or applied to every future month', function () {
    $user = User::factory()->create();
    $category = $user->household()->categories()->where('name', 'Groceries')->firstOrFail();
    $month = now()->startOfMonth();
    $year = $month->year;
    $monthNumber = $month->month;

    $category->budgetPlans()->whereDate('effective_month', $month->toDateString())->firstOrFail()
        ->update(['planned_amount' => 10000]);

    $this->actingAs($user)->post(route('household.plan.categories.budget', $category), [
        'amount' => '250.00',
        'year' => $year,
        'month' => $monthNumber,
        'apply_to_future_months' => false,
    ])->assertSessionHasNoErrors();

    expect((int) $category->budgetPlans()
        ->whereDate('effective_month', $month->toDateString())
        ->value('planned_amount'))->toBe(25000);
});

test('a category and category group can be created from the plan', function () {
    $user = User::factory()->create();
    $household = $user->household();

    $this->actingAs($user)
        ->post(route('household.plan.groups.store'), ['name' => 'Pets', 'direction' => 'expense'])
        ->assertRedirect();

    $group = CategoryGroup::query()->where('household_id', $household->id)->where('name', 'Pets')->firstOrFail();

    $this->actingAs($user)
        ->post(route('household.plan.categories.store'), [
            'name' => 'Pet Care',
            'category_group_id' => $group->id,
            'icon' => null,
            'year' => now()->year,
            'month' => now()->month,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $category = Category::query()->where('household_id', $household->id)->where('name', 'Pet Care')->firstOrFail();

    expect($category->category_group_id)->toBe($group->id)
        ->and($category->direction)->toBe('expense')
        ->and($group->direction)->toBe('expense');
    expect($category->budgetPlans()
        ->whereDate('effective_month', now()->startOfMonth()->toDateString())
        ->value('planned_amount'))->toBe(0);
});

test('month query updates the cursor', function () {
    $user = User::factory()->create();
    $prior = now()->subMonth();

    $this->actingAs($user)
        ->get(route('household.plan.index', ['year' => $prior->year, 'month' => $prior->month]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('year', $prior->year)
            ->where('month', $prior->month)
            ->where('monthLabel', $prior->format('F Y'))
        );
});

test('guests are redirected from the plan page', function () {
    $this->get(route('household.plan.index'))
        ->assertRedirect(route('login'));
});

test('the plan separates income expenses and transfers and leaves groups without a plan', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $paychecks = $household->categories()->where('name', 'Paychecks')->firstOrFail();
    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $paychecks->budgetPlans()->whereDate('effective_month', now()->startOfMonth()->toDateString())->update(['planned_amount' => 500000]);
    $groceries->budgetPlans()->whereDate('effective_month', now()->startOfMonth()->toDateString())->update(['planned_amount' => 200000]);

    $this->actingAs($user)
        ->get(route('household.plan.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('budgetSummary.left_to_budget_cents', 300000)
            ->where('budgetSummary.income.planned_cents', 500000)
            ->where('budgetSummary.expenses.planned_cents', 200000)
            ->where('groups', fn ($groups): bool => collect($groups)->every(
                fn (array $group): bool => ! array_key_exists('rollover_enabled', $group),
            ))
            ->where('categories', fn ($categories): bool => collect($categories)->contains(
                fn (array $row): bool => $row['name'] === 'Transfer' && $row['budgetable'] === false && $row['direction'] === 'transfer',
            ) && collect($categories)->contains(
                fn (array $row): bool => $row['name'] === 'Balance Adjustments' && $row['budgetable'] === false && $row['direction'] === 'transfer',
            ))
        );
});

test('a plan edit can stay in one month or cover this month and later months', function () {
    $this->travelTo('2026-06-15 12:00:00');
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'household_id' => $user->household()->id,
        'name' => 'Scoped Plan',
        'starting_month' => '2026-01-01',
        'starting_balance' => 0,
    ]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $category->id,
        'effective_month' => '2026-01-01',
        'planned_amount' => 4000,
    ]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $category->id,
        'effective_month' => '2026-06-01',
        'planned_amount' => 10000,
    ]);

    $this->actingAs($user)->post(route('household.plan.categories.budget', $category), [
        'amount' => '250.00',
        'year' => 2026,
        'month' => 6,
        'apply_to_future_months' => false,
    ])->assertSessionHasNoErrors();

    expect((int) $category->budgetPlans()->whereDate('effective_month', '2026-06-01')->value('planned_amount'))->toBe(25000)
        ->and((int) $category->budgetPlans()->whereDate('effective_month', '2026-07-01')->value('planned_amount'))->toBe(10000)
        ->and((int) $category->budgetPlans()->whereDate('effective_month', '2026-01-01')->value('planned_amount'))->toBe(4000);

    $this->actingAs($user)->post(route('household.plan.categories.budget', $category), [
        'amount' => '300.00',
        'year' => 2026,
        'month' => 6,
        'apply_to_future_months' => true,
    ])->assertSessionHasNoErrors();

    $budget = app(CategoryBudgetService::class);

    expect((int) $category->budgetPlans()->whereDate('effective_month', '2026-01-01')->value('planned_amount'))->toBe(4000)
        ->and((int) $category->budgetPlans()->whereDate('effective_month', '2026-06-01')->value('planned_amount'))->toBe(30000)
        ->and($budget->planForMonth($category, 2026, 8))->toBe(30000);
});

test('rollover on carries this month available into next month including a negative amount', function () {
    $this->travelTo('2026-03-15 12:00:00');
    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id]);
    $category = Category::factory()->create([
        'household_id' => $household->id,
        'name' => 'Rollover Probe',
        'starting_month' => '2026-03-01',
        'starting_balance' => 0,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -800,
        'date' => '2026-03-10',
        'created_by_user_id' => $user->id,
    ]);
    $budget = app(CategoryBudgetService::class);

    $this->actingAs($user)->post(route('household.plan.categories.rollover', $category), [
        'enabled' => true,
        'year' => 2026,
        'month' => 3,
    ])->assertSessionHasNoErrors();

    expect($budget->availableForMonth($category, 2026, 3))->toBe(-800)
        ->and($budget->summaryForMonth($category, 2026, 4)['carryover'])->toBe(-800);

    $this->actingAs($user)->post(route('household.plan.categories.rollover', $category), [
        'enabled' => false,
        'year' => 2026,
        'month' => 3,
    ])->assertSessionHasNoErrors();

    expect($budget->summaryForMonth($category, 2026, 4)['carryover'])->toBe(0);
});

test('income categories cannot enable rollover', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $incomeGroup = $household->categoryGroups()->where('name', 'Income')->firstOrFail();
    $category = Category::factory()->create([
        'household_id' => $household->id,
        'category_group_id' => $incomeGroup->id,
        'name' => 'Bonus Income',
    ]);

    $this->actingAs($user)->post(route('household.plan.categories.rollover', $category), [
        'enabled' => true,
        'year' => now()->year,
        'month' => now()->month,
    ])->assertForbidden();

    $this->assertDatabaseMissing('category_rollover_settings', ['category_id' => $category->id]);
});

test('another household cannot read or change a plan', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    Category::factory()->create([
        'household_id' => $owner->household()->id,
        'name' => 'Alpha Only Category',
    ]);
    $secret = $owner->household()->categories()->where('name', 'Alpha Only Category')->firstOrFail();

    $this->actingAs($intruder)
        ->get(route('household.plan.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where(
            'categories',
            fn ($categories): bool => ! collect($categories)->contains(fn (array $row): bool => $row['name'] === 'Alpha Only Category'),
        ));

    $this->actingAs($intruder)->post(route('household.plan.categories.budget', $secret), [
        'amount' => '10.00',
        'year' => now()->year,
        'month' => now()->month,
        'apply_to_future_months' => false,
    ])->assertForbidden();

    $ownerCsv = $this->actingAs($owner)->get(route('household.plan.export', ['year' => now()->year, 'month' => now()->month]))
        ->assertOk()
        ->streamedContent();
    $intruderCsv = $this->actingAs($intruder)->get(route('household.plan.export', ['year' => now()->year, 'month' => now()->month]))
        ->assertOk()
        ->streamedContent();

    expect($ownerCsv)->toContain('Alpha Only Category')
        ->and($intruderCsv)->not->toContain('Alpha Only Category')
        ->and($ownerCsv)->toContain('Groceries');
});
