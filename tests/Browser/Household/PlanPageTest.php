<?php

use App\Models\CategoryGroup;

it('creates category groups and budgets individual categories by month', function () {
    $this->travelTo('2026-09-07 12:00:00');

    $user = actingAsOwner();

    $page = visit('/household/plan');

    $page->assertSee('September 2026')
        ->assertSee('Expenses')
        ->assertSee('Progress')
        ->assertSee('Food & Dining')
        ->click('Add group')
        ->fill('#group-name', 'Dining')
        ->click('@submit-create-group')
        ->assertSee('Dining')
        ->click('Add category')
        ->fill('#category-name', 'Takeout')
        ->select('#category-group', CategoryGroup::query()->where('household_id', $user->household()->id)->where('name', 'Dining')->value('id'))
        ->select('#category-direction', 'expense')
        ->click('@submit-create-category')
        ->assertSee('Takeout')
        ->assertSee('Dining')
        ->click('@plan-next-month')
        ->assertSee('October 2026')
        ->click('@plan-prev-month')
        ->click('@plan-prev-month')
        ->assertSee('August 2026');

    $this->assertDatabaseHas('category_groups', [
        'household_id' => $user->household()->id,
        'name' => 'Dining',
    ]);
    $this->assertDatabaseHas('categories', [
        'household_id' => $user->household()->id,
        'name' => 'Takeout',
        'direction' => 'expense',
    ]);
});
