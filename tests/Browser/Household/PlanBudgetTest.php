<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetMove;
use App\Models\CategoryBudgetPlan;
use App\Models\Transaction;

it('prefills a positive source when opening move budget and saves the chosen amount', function () {
    $this->travelTo('2026-11-01 12:00:00');

    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $household = $user->household();
    $from = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $to = $household->categories()->where('name', 'Restaurants')->firstOrFail();
    $account = Account::factory()->create(['household_id' => $household->id]);

    $from->budgetPlans()->whereDate('effective_month', now()->startOfMonth()->toDateString())->update([
        'planned_amount' => 33300,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $to->id,
        'amount' => -49100,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);

    visit('/household/plan')
        ->resize(1762, 1014)
        ->click('[data-category-id="'.$from->id.'"]')
        ->assertSee('Move Budget')
        ->assertSeeIn('[data-test="move-from-picker"]', 'Groceries')
        ->assertSeeIn('[data-test="move-to-picker"]', 'Choose a category')
        ->assertValue('#move-amount', '333')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches()
        ->assertDisabled('@submit-move-money')
        ->click('[data-test="move-to-picker"]')
        ->click('[data-test="move-to-option-'.$to->id.'"]')
        ->fill('#move-amount', '50.00')
        ->assertEnabled('@submit-move-money')
        ->click('@submit-move-money')
        ->assertSee('Funds moved between categories.')
        ->assertNoJavaScriptErrors();

    $move = CategoryBudgetMove::query()->where('household_id', $household->id)->sole();
    expect($move->from_category_id)->toBe($from->id)
        ->and($move->to_category_id)->toBe($to->id)
        ->and($move->amount)->toBe(5000);
});

it('prefills an overspent category as the destination and an available source', function () {
    $this->travelTo('2026-11-01 12:00:00');

    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $household = $user->household();
    $from = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $to = $household->categories()->where('name', 'Restaurants')->firstOrFail();
    $account = Account::factory()->create(['household_id' => $household->id]);

    $from->budgetPlans()->whereDate('effective_month', now()->startOfMonth()->toDateString())->update([
        'planned_amount' => 33300,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $to->id,
        'amount' => -49100,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);

    visit('/household/plan')
        ->resize(1440, 1000)
        ->click('[data-category-id="'.$to->id.'"]')
        ->assertSeeIn('[data-test="move-from-picker"]', 'Groceries')
        ->assertSeeIn('[data-test="move-to-picker"]', 'Restaurants')
        ->assertValue('#move-amount', '333')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches();
});

it('keeps the move budget panel inside phone and tablet viewports', function () {
    $this->travelTo('2026-11-01 12:00:00');

    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $category = $user->household()->categories()->where('name', 'Groceries')->firstOrFail();
    $group = $category->group;

    $page = visit('/household/plan')
        ->resize(390, 844)
        ->click('[data-test="show-unbudgeted-'.$group->id.'"]')
        ->click('[data-category-id="'.$category->id.'"]')
        ->assertSee('Move Budget')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches(false);

    foreach ([[390, 844], [768, 1024], [1440, 1000], [1762, 1014]] as [$width, $height]) {
        $page->resize($width, $height);
        $geometry = $page->script('JSON.stringify({left: document.querySelector("[data-test=move-budget]").getBoundingClientRect().left, right: document.querySelector("[data-test=move-budget]").getBoundingClientRect().right, top: document.querySelector("[data-test=move-budget]").getBoundingClientRect().top, bottom: document.querySelector("[data-test=move-budget]").getBoundingClientRect().bottom, width: window.innerWidth, height: window.innerHeight})');
        $metrics = is_string($geometry) ? json_decode($geometry, true) : null;

        expect($metrics)->toBeArray()
            ->and($metrics['left'])->toBeGreaterThanOrEqual(0)
            ->and($metrics['right'])->toBeLessThanOrEqual($metrics['width'])
            ->and($metrics['top'])->toBeGreaterThanOrEqual(0)
            ->and($metrics['bottom'])->toBeLessThanOrEqual($metrics['height']);
    }
});

it('moves budget from an income category to an expense category', function () {
    $user = actingAsOwner();
    $household = $user->household();
    $incomeGroup = $household->categoryGroups()->where('direction', 'income')->firstOrFail();
    $income = Category::factory()->create([
        'household_id' => $household->id,
        'name' => 'Paycheck',
        'category_group_id' => $incomeGroup->id,
        'starting_month' => now()->startOfMonth()->toDateString(),
    ]);
    $expense = $household->categories()->where('name', 'Groceries')->firstOrFail();
    CategoryBudgetPlan::query()->create([
        'category_id' => $income->id,
        'effective_month' => now()->startOfMonth()->toDateString(),
        'planned_amount' => 10000,
    ]);

    visit('/household/plan')
        ->click('[data-category-id="'.$income->id.'"]')
        ->click('[data-test="move-to-picker"]')
        ->click('[data-test="move-to-option-'.$expense->id.'"]')
        ->fill('#move-amount', '50.00')
        ->click('@submit-move-money')
        ->assertSee('Funds moved between categories.')
        ->assertNoJavaScriptErrors();

    $move = CategoryBudgetMove::query()->where('household_id', $household->id)->sole();

    expect($move->from_category_id)->toBe($income->id)
        ->and($move->to_category_id)->toBe($expense->id)
        ->and($move->amount)->toBe(5000);
});
