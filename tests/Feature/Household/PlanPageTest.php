<?php

use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
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
        ->post(route('household.plan.groups.store'), ['name' => 'Pets'])
        ->assertRedirect();

    $group = CategoryGroup::query()->where('household_id', $household->id)->where('name', 'Pets')->firstOrFail();

    $this->actingAs($user)
        ->post(route('household.plan.categories.store'), [
            'name' => 'Pet Care',
            'category_group_id' => $group->id,
            'direction' => 'expense',
            'icon' => null,
            'year' => now()->year,
            'month' => now()->month,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $category = Category::query()->where('household_id', $household->id)->where('name', 'Pet Care')->firstOrFail();

    expect($category->category_group_id)->toBe($group->id)
        ->and($category->direction)->toBe('expense');
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
