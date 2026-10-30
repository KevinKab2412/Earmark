<?php

use App\Models\Account;
use App\Models\Transaction;

it('shows category budgeting and net worth cards', function () {
    actingAsOwner();

    visit('/household/dashboard')
        ->assertSee('Planned')
        ->assertSee('Available')
        ->assertSee('Over budget categories')
        ->assertSee('Investable assets')
        ->assertSee('Total net worth')
        ->assertSee('Target gap')
        ->assertSee('Add first financial position');
});

it('lists overspent categories and opens the plan from overview', function () {
    $this->travelTo('2026-09-07 12:00:00');

    $user = actingAsOwner();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id]);
    $category = $household->categories()->where('name', 'Groceries')->firstOrFail();
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -15000,
        'date' => '2026-09-07',
        'created_by_user_id' => $user->id,
    ]);

    visit('/household/dashboard')
        ->assertSee('Groceries')
        ->click('Open Budget')
        ->assertPathIs('/household/plan')
        ->assertSee('Groceries');
});

it('opens accounts from the overview shortcuts', function () {
    actingAsOwner();

    visit('/household/dashboard')
        ->click('Accounts')
        ->assertPathIs('/household/accounts')
        ->assertSee('No accounts yet');
});

it('reveals the card customization panel', function () {
    actingAsOwner();

    visit('/household/dashboard')
        ->assertDontSee('Choose the cards you want on your overview')
        ->click('Customize')
        ->assertSee('Choose the cards you want on your overview')
        ->assertSee('Review queue');
});
