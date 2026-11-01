<?php

use App\Models\CategoryGroup;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the categories page groups active categories by direction', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('household.categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('household/Categories')
            ->where('groups', fn ($groups): bool => collect($groups)->contains(
                fn (array $group): bool => $group['name'] === 'Income' && $group['direction'] === 'income'
            ) && collect($groups)->contains(
                fn (array $group): bool => $group['name'] === 'Housing' && $group['direction'] === 'expense'
            ) && collect($groups)->contains(
                fn (array $group): bool => $group['name'] === 'Transfers'
                    && $group['direction'] === 'transfer'
                    && collect($group['categories'])->contains(fn (array $category): bool => $category['name'] === 'Credit Card Payment')
            ))
        );
});

test('a category can be renamed and moved only within its existing direction', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $newGroup = CategoryGroup::factory()->create([
        'household_id' => $household->id,
        'name' => 'Everyday',
        'direction' => 'expense',
    ]);

    $this->actingAs($user)
        ->from(route('household.categories.index'))
        ->patch(route('household.categories.update', $category), [
            'name' => 'Market',
            'category_group_id' => $newGroup->id,
            'icon' => '🥬',
            'starting_month' => '2026-04',
            'starting_balance' => '12.34',
        ])
        ->assertRedirect(route('household.categories.index'))
        ->assertSessionHasNoErrors();

    expect($category->refresh()->name)->toBe('Market')
        ->and($category->category_group_id)->toBe($newGroup->id)
        ->and($category->icon)->toBe('🥬')
        ->and($category->starting_month->format('Y-m'))->toBe('2026-04')
        ->and($category->starting_balance)->toBe(1234)
        ->and($category->direction)->toBe('expense');
});

test('a category cannot move into a group with a different direction', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $category = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $transferGroup = $household->categoryGroups()->where('direction', 'transfer')->firstOrFail();

    $this->actingAs($user)
        ->from(route('household.categories.index'))
        ->patch(route('household.categories.update', $category), [
            'name' => $category->name,
            'category_group_id' => $transferGroup->id,
            'starting_month' => $category->starting_month->format('Y-m'),
            'starting_balance' => '0.00',
        ])
        ->assertSessionHasErrors('category_group_id');

    expect($category->refresh()->category_group_id)->not->toBe($transferGroup->id);
});

test('a category group can be renamed without changing its direction', function () {
    $user = User::factory()->create();
    $group = $user->household()->categoryGroups()->where('name', 'Housing')->firstOrFail();

    $this->actingAs($user)
        ->from(route('household.categories.index'))
        ->patch(route('household.category-groups.update', $group), [
            'name' => 'Home',
            'direction' => 'transfer',
        ])
        ->assertRedirect(route('household.categories.index'))
        ->assertSessionHasNoErrors();

    expect($group->refresh()->name)->toBe('Home')
        ->and($group->direction)->toBe('expense');
});

test('a household cannot edit another household category or group', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = $other->household()->categories()->where('name', 'Groceries')->firstOrFail();
    $group = $other->household()->categoryGroups()->where('name', 'Housing')->firstOrFail();

    $this->actingAs($user)
        ->patch(route('household.categories.update', $category), [
            'name' => 'Market',
            'category_group_id' => $category->category_group_id,
            'starting_month' => now()->format('Y-m'),
            'starting_balance' => '0.00',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('household.category-groups.update', $group), ['name' => 'Home'])
        ->assertForbidden();

    expect($category->refresh()->name)->toBe('Groceries')
        ->and($group->refresh()->name)->toBe('Housing');
});

test('guests are redirected from category management', function () {
    $this->get(route('household.categories.index'))
        ->assertRedirect(route('login'));
});
