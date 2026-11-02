<?php

use App\Models\Account;
use App\Models\Transaction;

/**
 * @param  list<string>  $labels
 */
function expectOverviewCardOrder(object $page, array $labels): void
{
    $text = (string) $page->text('[data-test="overview"]');
    $cursor = 0;

    foreach ($labels as $label) {
        $found = strpos($text, $label, $cursor);

        expect($found)->toBeInt()->toBeGreaterThanOrEqual($cursor);

        $cursor = $found + strlen($label);
    }
}

/**
 * Browser assertions do not retry until an Inertia save repaints the cards.
 *
 * @param  list<string>  $keys
 * @return list<string>
 */
function waitForOverviewOrder(object $page, array $keys): array
{
    $deadline = microtime(true) + 8;
    $actual = [];

    do {
        $read = $page->script('JSON.stringify([...document.querySelectorAll(\'[data-test="overview"] [data-card-key]\')].map((element) => element.getAttribute("data-card-key")))');
        $decoded = is_string($read) ? json_decode($read, true) : null;
        $actual = is_array($decoded) ? $decoded : [];

        if ($actual === $keys) {
            return $actual;
        }

        usleep(50_000);
    } while (microtime(true) < $deadline);

    return $actual;
}

function waitForOverviewCard(object $page, string $key): bool
{
    $deadline = microtime(true) + 8;
    $selector = json_encode('[data-test="overview-card-'.$key.'"]');
    $present = true;

    do {
        $present = $page->script('document.querySelector('.$selector.') !== null') === true;

        if (! $present) {
            return false;
        }

        usleep(50_000);
    } while (microtime(true) < $deadline);

    return $present;
}

it('shows the overview card set', function () {
    actingAsOwner();

    $page = visit('/household/dashboard')
        ->assertSeeIn('[data-test="overview-card-budget"]', 'Income')
        ->assertSeeIn('[data-test="overview-card-budget"]', 'Expenses')
        ->assertSeeIn('[data-test="budget-direction-income"]', '$0.00 planned')
        ->assertSeeIn('[data-test="budget-direction-expense"]', '$0.00 remaining')
        ->assertSeeIn('[data-test="overview-card-net-worth"]', 'Investable assets')
        ->assertSeeIn('[data-test="overview-card-net-worth"]', 'Total net worth')
        ->assertSeeIn('[data-test="overview-card-net-worth"]', 'Add first financial position')
        ->assertDontSeeIn('[data-test="overview-card-net-worth"]', 'Target gap')
        ->assertSeeIn('[data-test="overview-card-goals"]', 'No active goals.')
        ->assertSeeIn('[data-test="overview-card-spending"]', 'Spending')
        ->assertSeeIn('[data-test="overview-card-spending"]', 'This month vs. last month')
        ->assertSeeIn('[data-test="spending-total"]', '$0.00')
        ->assertSeeIn('[data-test="overview-card-transactions"]', 'Most recent')
        ->assertSeeIn('[data-test="overview-card-transactions"]', 'Upload a statement')
        ->assertDontSeeIn('[data-test="overview-card-transactions"]', '$')
        ->assertSeeIn('[data-test="overview-card-recurring"]', 'Confirmed schedules')
        ->assertSeeIn('[data-test="overview-card-investments"]', 'Open Holdings')
        ->assertDontSee('Review queue')
        ->assertDontSee('Scenarios')
        ->assertDontSee('Target gap')
        ->assertDontSee('Getting Started')
        ->assertDontSee('Top categories');

    $text = (string) $page->text('[data-test="overview"]');
    $cursor = 0;

    foreach ([
        'Budget',
        'Spending',
        'Transactions',
        'Recurring',
        'Net worth',
        'Investments',
        'Goals',
    ] as $label) {
        $found = strpos($text, $label, $cursor);

        expect($found)->toBeInt()->toBeGreaterThanOrEqual($cursor);

        $cursor = $found + strlen($label);
    }

    $page->click('View all')
        ->assertPathIs('/household/transactions')
        ->assertNoJavaScriptErrors();
});

it('opens the category plan from the overview', function () {
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
        ->click('Open Budget')
        ->assertPathIs('/household/plan')
        ->assertSee('Groceries')
        ->assertNoJavaScriptErrors();
});

it('opens accounts from the overview shortcuts', function () {
    actingAsOwner();

    visit('/household/dashboard')
        ->click('Accounts')
        ->assertPathIs('/household/accounts')
        ->assertSee('No accounts yet');
});

it('opens overview card switches in a modal', function () {
    actingAsOwner();

    visit('/household/dashboard')
        ->assertDontSee('Choose the cards you want on your overview')
        ->click('Customize')
        ->assertDontSee('Choose the cards you want on your overview')
        ->assertVisible('[role="dialog"]')
        ->assertAttribute('@overview-card-switch-budget', 'role', 'switch')
        ->assertPresent('@overview-card-switch-budget')
        ->assertPresent('@overview-card-switch-net-worth')
        ->assertPresent('@overview-card-switch-goals')
        ->assertPresent('@overview-card-switch-spending')
        ->assertPresent('@overview-card-switch-transactions')
        ->assertPresent('@overview-card-switch-recurring')
        ->assertPresent('@overview-card-switch-investments')
        ->assertAriaAttribute('@overview-card-switch-budget', 'checked', 'true')
        ->assertDontSee('Review queue')
        ->assertDontSee('Scenarios')
        ->assertNoJavaScriptErrors();
});

it('paints overview cards in the saved list order', function () {
    $user = actingAsOwner();
    $user->household()->update([
        'overview_cards' => [
            'spending',
            'budget',
            'net_worth',
            'goals',
            'transactions',
            'recurring',
            'investments',
        ],
    ]);

    $page = visit('/household/dashboard')
        ->assertNoJavaScriptErrors();

    expectOverviewCardOrder($page, [
        'Spending',
        'Budget',
        'Net worth',
        'Goals',
        'Transactions',
        'Recurring',
        'Investments',
    ]);
});

it('keeps a hidden overview card hidden after refresh', function () {
    $user = actingAsOwner();

    $page = visit('/household/dashboard')
        ->click('Customize')
        ->assertAttribute('@overview-card-switch-investments', 'role', 'switch')
        ->assertAriaAttribute('@overview-card-switch-investments', 'checked', 'true')
        ->click('@overview-card-switch-investments')
        ->assertAriaAttribute('@overview-card-switch-investments', 'checked', 'false');

    expect(waitForOverviewCard($page, 'investments'))->toBeFalse();

    $page->refresh()
        ->assertNotPresent('@overview-card-investments')
        ->assertPresent('@overview-card-budget')
        ->assertNoJavaScriptErrors();

    expect((string) $page->text('[data-test="overview"]'))->not->toContain('Investments');
    expect($user->household()->fresh()->overview_cards)->toBe([
        'budget',
        'spending',
        'transactions',
        'recurring',
        'net_worth',
        'goals',
    ]);
});

it('keeps a dragged overview card first after refresh', function () {
    $user = actingAsOwner();
    $order = [
        'spending',
        'budget',
        'transactions',
        'recurring',
        'net_worth',
        'investments',
        'goals',
    ];

    $page = visit('/household/dashboard')
        ->drag('@overview-card-drag-spending', '@overview-card-drag-budget');

    expect(waitForOverviewOrder($page, $order))->toBe($order);

    $page->refresh()->assertNoJavaScriptErrors();

    expectOverviewCardOrder($page, [
        'Spending',
        'Budget',
        'Transactions',
        'Recurring',
        'Net worth',
        'Investments',
        'Goals',
    ]);
    expect($user->household()->fresh()->overview_cards)->toBe($order);
});
