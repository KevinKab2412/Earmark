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
        ])
        ->assertRedirect(route('household.import.index'))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('categories', [
        'household_id' => $user->household()->id,
        'name' => 'Streaming',
        'category_group_id' => $group->id,
    ]);
});

test('creating a category requires a valid group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('household.import.index'))
        ->post(route('household.categories.store'), [
            'name' => 'Whatever',
            'category_group_id' => 'missing-group',
        ])
        ->assertSessionHasErrors('category_group_id');

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
        ])
        ->assertSessionHasErrors('name');
});

test('a category inherits its type from the selected group', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $group = $household->categoryGroups()->where('name', 'Transfers')->firstOrFail();

    $this->actingAs($user)
        ->from(route('household.import.index'))
        ->post(route('household.categories.store'), [
            'name' => 'Account Transfer',
            'category_group_id' => $group->id,
            'direction' => 'expense',
        ])
        ->assertRedirect(route('household.import.index'))
        ->assertSessionHasNoErrors();

    $category = $household->categories()->where('name', 'Account Transfer')->firstOrFail();

    expect($category->direction)->toBe('transfer')
        ->and($category->kind)->toBe('standard')
        ->and($group->direction)->toBe('transfer');
});

test('guests cannot create categories', function () {
    $this->post(route('household.categories.store'), ['name' => 'X'])
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
        ->and($categories['Balance Adjustments']->direction)->toBe('transfer')
        ->and($categories['Balance Adjustments']->kind)->toBe('balance_adjustment');

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
