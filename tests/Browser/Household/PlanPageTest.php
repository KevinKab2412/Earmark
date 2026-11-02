<?php

use App\Models\CategoryGroup;

function assertPlanPixelBaseline(int $width, int $height): void
{
    test()->travelTo('2026-11-01 12:00:00');
    actingAsOwner(['name' => 'Pixel Baseline']);

    visit('/household/plan')
        ->resize($width, $height)
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches();
}

it('matches the desktop budget frame geometry', function () {
    actingAsOwner();

    $page = visit('/household/plan')->resize(1920, 1192);
    $geometry = $page->script('JSON.stringify({pageLeft: document.querySelector("[data-test=plan-page]").getBoundingClientRect().left, columnsLeft: document.querySelector("[data-test=plan-columns]").getBoundingClientRect().left, columnsTop: document.querySelector("[data-test=plan-columns]").getBoundingClientRect().top, summaryLeft: document.querySelector("[data-test=left-to-budget]").getBoundingClientRect().left, summaryWidth: document.querySelector("[data-test=left-to-budget]").getBoundingClientRect().width})');
    $metrics = is_string($geometry) ? json_decode($geometry, true) : null;

    expect($metrics)->toBeArray()
        ->and($metrics['pageLeft'])->toBe(288)
        ->and($metrics['columnsLeft'])->toBe(304)
        ->and($metrics['columnsTop'])->toBe(80)
        ->and($metrics['summaryLeft'])->toBe(1440)
        ->and($metrics['summaryWidth'])->toBe(464);
});

it('pixel-diffs the mobile budget at 390 pixels', function () {
    assertPlanPixelBaseline(390, 844);
});

it('pixel-diffs the tablet budget at 768 pixels', function () {
    assertPlanPixelBaseline(768, 1024);
});

it('pixel-diffs the desktop budget at 1440 pixels', function () {
    assertPlanPixelBaseline(1440, 1000);
});

it('pixel-diffs the category plan editor with future-month and rollover controls', function () {
    $this->travelTo('2026-11-01 12:00:00');
    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $foodGroup = $user->household()->categoryGroups()->where('name', 'Food & Dining')->firstOrFail();

    visit('/household/plan')
        ->resize(1440, 1000)
        ->click('[data-test="show-unbudgeted-'.$foodGroup->id.'"]')
        ->click('@edit-category-budget')
        ->assertSee('Apply this amount to all future months')
        ->assertSee('Roll a positive or negative balance into next month')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches(false);
});

it('keeps the category plan editor inside the mobile viewport', function () {
    $this->travelTo('2026-11-01 12:00:00');
    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $foodGroup = $user->household()->categoryGroups()->where('name', 'Food & Dining')->firstOrFail();

    $page = visit('/household/plan')
        ->resize(390, 844)
        ->click('[data-test="show-unbudgeted-'.$foodGroup->id.'"]')
        ->click('@edit-category-budget')
        ->assertSee('Roll a positive or negative balance into next month')
        ->assertNoJavaScriptErrors();
    $geometry = $page->script('JSON.stringify({left: document.querySelector("[data-test=budget-editor]").getBoundingClientRect().left, right: document.querySelector("[data-test=budget-editor]").getBoundingClientRect().right, width: window.innerWidth})');
    $metrics = is_string($geometry) ? json_decode($geometry, true) : null;

    expect($metrics)->toBeArray()
        ->and($metrics['left'])->toBeGreaterThanOrEqual(0)
        ->and($metrics['right'])->toBeLessThanOrEqual($metrics['width']);

    $page->assertScreenshotMatches(false);
});

it('creates category groups and budgets individual categories by month', function () {
    $this->travelTo('2026-09-07 12:00:00');

    $user = actingAsOwner();

    $page = visit('/household/plan');

    $page->assertSee('September 2026')
        ->assertSee('Today')
        ->assertSee('Income')
        ->assertSee('Expenses')
        ->assertSee('Transfers')
        ->assertSee('Progress')
        ->assertSee('Planned')
        ->assertSee('Actual')
        ->assertSee('Remaining')
        ->assertSee('Total Income')
        ->assertSee('Left to budget')
        ->assertSee('Earned')
        ->assertSee('Spent')
        ->assertSee('Summary')
        ->assertSee('Download CSV')
        ->assertSee('Food & Dining')
        ->assertSee('unbudgeted')
        ->click('Add group')
        ->fill('#group-name', 'Dining')
        ->select('#group-direction', 'expense')
        ->click('@submit-create-group')
        ->assertSee('Dining')
        ->click('Add category')
        ->fill('#category-name', 'Takeout')
        ->select('#category-group', CategoryGroup::query()->where('household_id', $user->household()->id)->where('name', 'Dining')->value('id'))
        ->click('@submit-create-category')
        ->assertDontSee('Takeout')
        ->assertSee('Dining');

    $diningId = CategoryGroup::query()->where('household_id', $user->household()->id)->where('name', 'Dining')->value('id');

    $page->click('[data-test="show-unbudgeted-'.$diningId.'"]')
        ->assertSee('Takeout')
        ->assertSee('Dining')
        ->click('@plan-next-month')
        ->assertSee('October 2026')
        ->click('@plan-prev-month')
        ->assertSee('September 2026')
        ->click('@plan-prev-month')
        ->assertSee('August 2026')
        ->click('@plan-today')
        ->assertSee('September 2026')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('category_groups', [
        'household_id' => $user->household()->id,
        'name' => 'Dining',
        'direction' => 'expense',
    ]);
    $this->assertDatabaseHas('categories', [
        'household_id' => $user->household()->id,
        'name' => 'Takeout',
        'category_group_id' => $diningId,
    ]);
});
