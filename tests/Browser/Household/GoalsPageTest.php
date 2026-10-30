<?php

it('creates a save-up goal linked to a category', function () {
    $user = actingAsOwner();
    $category = $user->household()->categories()->where('name', 'Vacation')->firstOrFail();

    visit('/household/goals')
        ->click('@open-create-goal')
        ->fill('goal-name', 'Trip to Japan')
        ->fill('goal-target', '500000')
        ->select('#goal-category', $category->id)
        ->click('@submit-goal')
        ->assertSee('Trip to Japan')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('goals', [
        'name' => 'Trip to Japan',
        'type' => 'save_up',
        'linked_category_id' => $category->id,
    ]);
});
