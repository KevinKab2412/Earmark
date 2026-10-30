<?php

use App\Models\Account;
use App\Models\CategoryBudgetMove;
use App\Models\Transaction;

it('opens move budget with empty endpoints and saves the chosen amount', function () {
    $user = actingAsOwner();
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
        ->click('[data-category-id="'.$from->id.'"]')
        ->assertSee('Move Budget')
        ->assertValue('#move-from', '')
        ->assertValue('#move-to', '')
        ->assertValue('#move-amount', '0')
        ->assertDisabled('@submit-move-money')
        ->select('#move-from', $from->id)
        ->select('#move-to', $to->id)
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
