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
