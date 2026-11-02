<?php

it('creates a save-up goal linked to a category', function () {
    $user = actingAsOwner();
    $category = $user->household()->categories()->where('name', 'Vacation')->firstOrFail();

    visit('/household/goals')
        ->click('@open-create-goal')
        ->fill('goal-name', 'Trip to Japan')
        ->fill('goal-target', '5000')
        ->select('#goal-category', $category->id)
        ->click('@submit-goal')
        ->assertSee('Trip to Japan')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('goals', [
        'name' => 'Trip to Japan',
        'type' => 'save_up',
        'linked_category_id' => $category->id,
        'target_amount' => 500000,
    ]);
});

it('edits, archives, and restores a goal with currency inputs', function () {
    $user = actingAsOwner();
    $category = $user->household()->categories()->where('name', 'Vacation')->firstOrFail();

    visit('/household/goals')
        ->click('@open-create-goal')
        ->fill('goal-name', 'Trip to Japan')
        ->fill('goal-target', '5000')
        ->select('#goal-category', $category->id)
        ->click('@submit-goal')
        ->click('@edit-goal')
        ->fill('goal-name', 'Japan trip')
        ->fill('goal-target', '6000')
        ->click('@submit-goal')
        ->click('@archive-goal')
        ->click('@show-archived-goals')
        ->assertSee('Japan trip')
        ->click('@restore-goal')
        ->click('@show-active-goals')
        ->assertSee('Japan trip')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('goals', [
        'name' => 'Japan trip',
        'type' => 'save_up',
        'linked_category_id' => $category->id,
        'target_amount' => 600000,
        'status' => 'active',
    ]);
});
