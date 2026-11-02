<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;

/**
 * @param  array<string, mixed>  $attributes
 */
function browserTransaction(User $user, array $attributes = []): Transaction
{
    $household = $user->household();

    return Transaction::factory()->create(array_merge([
        'household_id' => $household->id,
        'account_id' => Account::factory()->create(['household_id' => $household->id, 'name' => 'Daily Chequing'])->id,
        'category_id' => null,
        'created_by_user_id' => $user->id,
    ], $attributes));
}

it('edits a transaction from the register', function () {
    $user = actingAsOwner();
    browserTransaction($user, ['payee' => 'Old Payee', 'amount' => -1000]);

    visit('/household/transactions')
        ->assertSee('Old Payee')
        ->click('@edit-row')
        ->fill('edit-payee', 'New Payee')
        ->click('@save-edit')
        ->assertSee('New Payee')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('transactions', ['payee' => 'New Payee']);
    $this->assertDatabaseHas('transaction_activities', ['action' => 'updated']);
});

it('creates a category inline from the edit modal', function () {
    $user = actingAsOwner();
    browserTransaction($user, ['payee' => 'Paycheck', 'amount' => 500000]);

    visit('/household/transactions')
        ->click('@edit-row')
        ->assertSee('Edit transaction')
        ->click('@edit-new-category')
        ->assertSee('Create a category')
        ->fill('@new-category-name', 'Salary')
        ->select('#new-category-group', CategoryGroup::query()->where('household_id', $user->household()->id)->where('direction', 'income')->value('id'))
        ->click('@new-category-save')
        ->assertDontSee('Create a category')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('categories', [
        'household_id' => $user->household()->id,
        'name' => 'Salary',
        'category_group_id' => CategoryGroup::query()->where('household_id', $user->household()->id)->where('direction', 'income')->value('id'),
    ]);
});

it('opens the edit panel from a populated register', function () {
    $user = actingAsOwner();

    // Edit opens a centered modal, so it must be reachable regardless of how many
    // rows precede the clicked one.
    foreach (range(1, 20) as $i) {
        browserTransaction($user, ['payee' => "Merchant {$i}", 'amount' => -100 * $i]);
    }

    visit('/household/transactions')
        ->click('@edit-row')
        ->assertSee('Edit transaction')
        ->fill('edit-payee', 'Edited From List')
        ->click('@save-edit')
        ->assertSee('Edited From List')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('transactions', ['payee' => 'Edited From List']);
});

it('filters the register by payee', function () {
    $user = actingAsOwner();
    browserTransaction($user, ['payee' => 'Loblaws Store']);
    browserTransaction($user, ['payee' => 'Shell Fuel']);

    visit('/household/transactions')
        ->fill('f-payee', 'Loblaws')
        ->click('@apply-filters')
        ->assertSee('Loblaws Store')
        ->assertDontSee('Shell Fuel')
        ->assertNoJavaScriptErrors();
});

it('bulk marks transactions reviewed', function () {
    $user = actingAsOwner();
    browserTransaction($user, ['payee' => 'To Review', 'reviewed' => false]);

    visit('/household/transactions')
        ->assertSee('Needs review')
        ->click('@select-row')
        ->click('@bulk-reviewed')
        ->assertSee('Reviewed')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('transactions', ['payee' => 'To Review', 'reviewed' => true]);
});

function createTransactionPixelBaseline(): void
{
    test()->travelTo('2026-11-01 12:00:00');
    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $household = $user->household();
    $account = Account::factory()->create([
        'household_id' => $household->id,
        'name' => 'Daily Chequing',
        'type' => 'chequing',
    ]);
    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $incomeGroup = $household->categoryGroups()->where('direction', 'income')->firstOrFail();
    $paycheck = Category::factory()->create([
        'household_id' => $household->id,
        'category_group_id' => $incomeGroup->id,
        'name' => 'Paycheck',
        'starting_month' => now()->startOfMonth()->toDateString(),
    ]);

    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'created_by_user_id' => $user->id,
        'payee' => 'Loblaws #5025',
        'amount' => -7250,
        'date' => '2026-11-01',
        'source' => 'imported_csv',
        'reviewed' => false,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $paycheck->id,
        'created_by_user_id' => $user->id,
        'payee' => 'Payroll Deposit',
        'amount' => 200000,
        'date' => '2026-10-31',
        'reviewed' => true,
    ]);
}

function assertTransactionPixelBaseline(int $width, int $height): void
{
    createTransactionPixelBaseline();

    visit('/household/transactions')
        ->resize($width, $height)
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches();
}

it('pixel-diffs the transaction register at 390 pixels', function () {
    assertTransactionPixelBaseline(390, 844);
});

it('pixel-diffs the transaction register at 768 pixels', function () {
    assertTransactionPixelBaseline(768, 1024);
});

it('pixel-diffs the transaction register at 1440 pixels', function () {
    assertTransactionPixelBaseline(1440, 1000);
});

it('pixel-diffs the transaction editor', function () {
    createTransactionPixelBaseline();

    visit('/household/transactions')
        ->resize(1440, 1000)
        ->click('@edit-row')
        ->assertSee('Edit transaction')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches(false);
});
