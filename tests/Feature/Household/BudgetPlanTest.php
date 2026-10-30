<?php

use App\Models\Category;
use App\Models\CategoryBudgetMove;
use App\Models\CategoryBudgetPlan;
use App\Models\User;
use App\Services\CategoryBudgetService;

test('a user can move planned funds from one category to another', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $source = Category::factory()->create([
        'household_id' => $household->id,
        'name' => 'Move Source',
        'created_at' => now()->startOfMonth(),
    ]);
    $destination = Category::factory()->create([
        'household_id' => $household->id,
        'name' => 'Move Destination',
        'created_at' => now()->startOfMonth(),
    ]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $source->id,
        'effective_month' => now()->startOfMonth()->toDateString(),
        'planned_amount' => 10000,
    ]);

    $this->actingAs($user)
        ->post(route('household.plan.move'), [
            'from_category_id' => $source->id,
            'to_category_id' => $destination->id,
            'amount' => '40.00',
            'year' => now()->year,
            'month' => now()->month,
            'memo' => 'Cover dining',
        ])
        ->assertRedirect(route('household.plan.index', ['year' => now()->year, 'month' => now()->month]))
        ->assertSessionHasNoErrors();

    $budget = app(CategoryBudgetService::class);

    expect(CategoryBudgetMove::query()->where('household_id', $household->id)->count())->toBe(1)
        ->and($budget->availableForMonth($source, now()->year, now()->month))->toBe(6000)
        ->and($budget->availableForMonth($destination, now()->year, now()->month))->toBe(4000)
        ->and($budget->availableForMonth($source, now()->year, now()->month) + $budget->availableForMonth($destination, now()->year, now()->month))->toBe(10000);
});

test('moving 50.00 from groceries to restaurants changes available and leaves both plans', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $restaurants = $household->categories()->where('name', 'Restaurants')->firstOrFail();
    $month = now()->startOfMonth()->toDateString();
    $groceries->budgetPlans()->whereDate('effective_month', $month)->update(['planned_amount' => 8000]);

    $this->actingAs($user)
        ->post(route('household.plan.move'), [
            'from_category_id' => $groceries->id,
            'to_category_id' => $restaurants->id,
            'amount' => '50.00',
            'year' => now()->year,
            'month' => now()->month,
            'memo' => 'Dinner',
        ])
        ->assertRedirect(route('household.plan.index', ['year' => now()->year, 'month' => now()->month]))
        ->assertSessionHasNoErrors();

    $move = CategoryBudgetMove::query()->where('household_id', $household->id)->sole();
    $budget = app(CategoryBudgetService::class);

    expect($move->amount)->toBe(5000)
        ->and($move->from_category_id)->toBe($groceries->id)
        ->and($move->to_category_id)->toBe($restaurants->id)
        ->and($move->memo)->toBe('Dinner')
        ->and((int) $groceries->budgetPlans()->whereDate('effective_month', $month)->value('planned_amount'))->toBe(8000)
        ->and((int) $restaurants->budgetPlans()->whereDate('effective_month', $month)->value('planned_amount'))->toBe(0)
        ->and($budget->availableForMonth($groceries, now()->year, now()->month))->toBe(3000)
        ->and($budget->availableForMonth($restaurants, now()->year, now()->month))->toBe(5000);
});

test('a move is rejected for zero a missing endpoint a group a transfer category or another household', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $restaurants = $household->categories()->where('name', 'Restaurants')->firstOrFail();
    $group = $household->categoryGroups()->where('name', 'Food & Dining')->firstOrFail();
    $transfer = $household->categories()->where('name', 'Transfer')->firstOrFail();
    $other = User::factory()->create();
    $foreign = $other->household()->categories()->where('name', 'Restaurants')->firstOrFail();
    $payload = [
        'from_category_id' => $groceries->id,
        'to_category_id' => $restaurants->id,
        'amount' => '50.00',
        'year' => now()->year,
        'month' => now()->month,
    ];

    $this->actingAs($user)->from(route('household.plan.index'))
        ->post(route('household.plan.move'), [...$payload, 'amount' => '0'])
        ->assertSessionHasErrors('amount');

    $this->actingAs($user)->from(route('household.plan.index'))
        ->post(route('household.plan.move'), [
            'from_category_id' => $groceries->id,
            'amount' => '50.00',
            'year' => now()->year,
            'month' => now()->month,
        ])
        ->assertSessionHasErrors('to_category_id');

    $this->actingAs($user)->from(route('household.plan.index'))
        ->post(route('household.plan.move'), [...$payload, 'to_category_id' => $group->id])
        ->assertSessionHasErrors('to_category_id');

    $this->actingAs($user)->from(route('household.plan.index'))
        ->post(route('household.plan.move'), [...$payload, 'to_category_id' => $transfer->id])
        ->assertSessionHasErrors('to_category_id');

    $this->actingAs($user)->from(route('household.plan.index'))
        ->post(route('household.plan.move'), [...$payload, 'to_category_id' => $foreign->id])
        ->assertSessionHasErrors('to_category_id');

    expect(CategoryBudgetMove::query()->count())->toBe(0);
});

test('a category cannot receive a move from itself or another household', function () {
    $user = User::factory()->create();
    $source = Category::factory()->create(['household_id' => $user->household()->id]);
    $other = Category::factory()->create();

    $this->actingAs($user)
        ->from(route('household.plan.index'))
        ->post(route('household.plan.move'), [
            'from_category_id' => $source->id,
            'to_category_id' => $source->id,
            'amount' => '10.00',
            'year' => now()->year,
            'month' => now()->month,
        ])
        ->assertSessionHasErrors('to_category_id');

    $this->actingAs($user)
        ->from(route('household.plan.index'))
        ->post(route('household.plan.move'), [
            'from_category_id' => $source->id,
            'to_category_id' => $other->id,
            'amount' => '10.00',
            'year' => now()->year,
            'month' => now()->month,
        ])
        ->assertSessionHasErrors('to_category_id');
});
