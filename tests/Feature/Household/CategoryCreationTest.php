<?php

use App\Models\User;

test('a household member can create a category and return to the caller', function () {
    $user = User::factory()->create();
    $group = $user->household()->categoryGroups()->where('name', 'Other')->firstOrFail();

    $this->actingAs($user)
        ->from(route('household.import.index'))
        ->post(route('household.categories.store'), [
            'name' => 'Streaming',
            'category_group_id' => $group->id,
            'direction' => 'expense',
        ])
        ->assertRedirect(route('household.import.index'))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('categories', [
        'household_id' => $user->household()->id,
        'name' => 'Streaming',
        'category_group_id' => $group->id,
        'direction' => 'expense',
    ]);
});

test('creating a category requires a valid group and direction', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('household.import.index'))
        ->post(route('household.categories.store'), [
            'name' => 'Whatever',
            'category_group_id' => 'missing-group',
            'direction' => 'outbound',
        ])
        ->assertSessionHasErrors(['category_group_id', 'direction']);

    $this->assertDatabaseMissing('categories', ['name' => 'Whatever']);
});

test('category names are unique within a household', function () {
    $user = User::factory()->create();
    $group = $user->household()->categoryGroups()->where('name', 'Other')->firstOrFail();

    $this->actingAs($user)
        ->from(route('household.import.index'))
        ->post(route('household.categories.store'), [
            'name' => 'Groceries',
            'category_group_id' => $group->id,
            'direction' => 'expense',
        ])
        ->assertSessionHasErrors('name');
});

test('guests cannot create categories', function () {
    $this->post(route('household.categories.store'), ['name' => 'X', 'direction' => 'expense'])
        ->assertRedirect(route('login'));
});

test('transfer and adjustment categories are grouped together and excluded from budgets', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $categories = $household->categories()->whereIn('name', [
        'Transfer',
        'Credit Card Payment',
        'Balance Adjustments',
    ])->get()->keyBy('name');

    expect($categories)->toHaveCount(3)
        ->and($categories['Transfer']->category_group_id)->toBe($categories['Credit Card Payment']->category_group_id)
        ->and($categories['Transfer']->category_group_id)->toBe($categories['Balance Adjustments']->category_group_id)
        ->and($categories['Transfer']->direction)->toBe('transfer')
        ->and($categories['Credit Card Payment']->direction)->toBe('transfer')
        ->and($categories['Balance Adjustments']->direction)->toBe('adjustment');

    $this->assertDatabaseMissing('category_budget_plans', [
        'category_id' => $categories['Transfer']->id,
    ]);
    $this->assertDatabaseMissing('category_budget_plans', [
        'category_id' => $categories['Credit Card Payment']->id,
    ]);
    $this->assertDatabaseMissing('category_budget_plans', [
        'category_id' => $categories['Balance Adjustments']->id,
    ]);
});
