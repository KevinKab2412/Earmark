<?php

it('opens category settings and edits a group and category', function () {
    $user = actingAsOwner();
    $household = $user->household();
    $group = $household->categoryGroups()->where('name', 'Food & Dining')->firstOrFail();
    $category = $household->categories()->where('name', 'Groceries')->firstOrFail();

    visit('/household/categories')
        ->assertSee('Categories')
        ->assertSee('Food & Dining')
        ->click('@category-tab-transfer')
        ->assertSee('Credit Card Payment')
        ->click('@category-tab-expense')
        ->click('@edit-group-'.$group->id)
        ->fill('#category-group-name', 'Everyday')
        ->click('@submit-category-group')
        ->assertSee('Everyday')
        ->click('@edit-category-'.$category->id)
        ->fill('#category-name', 'Market')
        ->fill('#category-icon', '🥬')
        ->click('@submit-category')
        ->assertSee('Market')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('category_groups', [
        'id' => $group->id,
        'name' => 'Everyday',
        'direction' => 'expense',
    ]);
    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Market',
        'icon' => '🥬',
        'category_group_id' => $group->id,
    ]);
});

it('creates a category group and category from category settings', function () {
    $user = actingAsOwner();
    $household = $user->household();

    $page = visit('/household/categories')
        ->click('@create-category-group')
        ->fill('#category-group-name', 'Pet Care')
        ->click('@submit-category-group')
        ->assertSee('Pet Care');

    $group = $household->categoryGroups()->where('name', 'Pet Care')->sole();

    $page->click('@create-category-in-'.$group->id)
        ->fill('#category-name', 'Vet visits')
        ->fill('#category-icon', '🐾')
        ->click('@submit-category')
        ->assertSee('Vet visits')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('category_groups', [
        'id' => $group->id,
        'direction' => 'expense',
    ]);
    $this->assertDatabaseHas('categories', [
        'household_id' => $household->id,
        'category_group_id' => $group->id,
        'name' => 'Vet visits',
        'icon' => '🐾',
    ]);
});
