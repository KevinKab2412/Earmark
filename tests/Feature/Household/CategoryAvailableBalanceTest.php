<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetMove;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryRolloverSetting;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\CategoryBudgetService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->travelTo('2026-03-15 12:00:00');
    $this->user = User::factory()->create();
    $this->household = $this->user->household();
    $this->account = Account::factory()->create(['household_id' => $this->household->id]);
    $this->category = Category::factory()->create([
        'household_id' => $this->household->id,
        'name' => 'Balance Probe',
        'starting_month' => '2026-03-01',
        'starting_balance' => 10000,
    ]);
    $this->budget = app(CategoryBudgetService::class);
});

test('the starting month available balance is the opening balance when nothing else is assigned', function () {
    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(10000);
});

test('a plan raises the starting month available balance by the planned cents', function () {
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'planned_amount' => 5000,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(15000)
        ->and($this->budget->planForMonth($this->category, 2026, 3))->toBe(5000);
});

test('an expense lowers available by that expense amount', function () {
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'planned_amount' => 5000,
    ]);
    Transaction::factory()->create([
        'household_id' => $this->household->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -2000,
        'date' => '2026-03-10',
        'created_by_user_id' => $this->user->id,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(13000);
});

test('rollover on carries a positive ending balance into the next incoming balance', function () {
    $this->category->update(['starting_balance' => 0]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'planned_amount' => 1500,
    ]);
    CategoryRolloverSetting::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'enabled' => true,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(1500)
        ->and($this->budget->summaryForMonth($this->category, 2026, 4)['carryover'])->toBe(1500);
});

test('rollover on carries a negative ending balance into the next incoming balance', function () {
    $this->category->update(['starting_balance' => 0]);
    CategoryRolloverSetting::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'enabled' => true,
    ]);
    Transaction::factory()->create([
        'household_id' => $this->household->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -800,
        'date' => '2026-03-10',
        'created_by_user_id' => $this->user->id,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(-800)
        ->and($this->budget->summaryForMonth($this->category, 2026, 4)['carryover'])->toBe(-800);
});

test('rollover off makes the next incoming balance zero after a positive or negative ending', function () {
    $this->category->update(['starting_balance' => 0]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'planned_amount' => 1500,
    ]);
    CategoryRolloverSetting::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'enabled' => false,
    ]);

    expect($this->budget->summaryForMonth($this->category, 2026, 4)['carryover'])->toBe(0);

    Transaction::factory()->create([
        'household_id' => $this->household->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -2300,
        'date' => '2026-03-12',
        'created_by_user_id' => $this->user->id,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(-800)
        ->and($this->budget->summaryForMonth($this->category, 2026, 4)['carryover'])->toBe(0);
});

test('the source month rollover setting decides the carry and opening balance is not added again', function () {
    CategoryRolloverSetting::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'enabled' => true,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 4))->toBe(10000);

    CategoryRolloverSetting::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-04-01',
        'enabled' => false,
    ]);

    expect($this->budget->summaryForMonth($this->category, 2026, 4)['carryover'])->toBe(10000)
        ->and($this->budget->summaryForMonth($this->category, 2026, 5)['carryover'])->toBe(0)
        ->and($this->budget->summaryForMonth($this->category, 2026, 5)['opening_balance'])->toBe(0);
});

test('a move changes available without changing the planned amount', function () {
    $other = Category::factory()->create([
        'household_id' => $this->household->id,
        'name' => 'Move Counterpart',
        'starting_month' => '2026-03-01',
        'starting_balance' => 0,
    ]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'planned_amount' => 5000,
    ]);
    CategoryBudgetMove::query()->create([
        'household_id' => $this->household->id,
        'from_category_id' => $other->id,
        'to_category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'amount' => 400,
        'created_by_user_id' => $this->user->id,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(15400)
        ->and($this->budget->planForMonth($this->category, 2026, 3))->toBe(5000);

    CategoryBudgetMove::query()->create([
        'household_id' => $this->household->id,
        'from_category_id' => $this->category->id,
        'to_category_id' => $other->id,
        'effective_month' => '2026-03-01',
        'amount' => 400,
        'created_by_user_id' => $this->user->id,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(15000)
        ->and($this->budget->planForMonth($this->category, 2026, 3))->toBe(5000);
});

test('a paired transfer and a split of that transfer do not change available', function () {
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'planned_amount' => 5000,
    ]);
    $pairId = (string) Str::ulid();
    $paired = Transaction::factory()->create([
        'household_id' => $this->household->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -9000,
        'date' => '2026-03-11',
        'transfer_pair_id' => $pairId,
        'is_split' => true,
        'created_by_user_id' => $this->user->id,
    ]);
    TransactionSplit::factory()->create([
        'transaction_id' => $paired->id,
        'category_id' => $this->category->id,
        'amount' => -9000,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(15000)
        ->and($this->budget->summaryForMonth($this->category, 2026, 3)['activity'])->toBe(0);
});

test('another household activity and moves stay invisible', function () {
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-03-01',
        'planned_amount' => 5000,
    ]);
    $otherUser = User::factory()->create();
    $otherHousehold = $otherUser->household();
    $otherAccount = Account::factory()->create(['household_id' => $otherHousehold->id]);
    Transaction::factory()->create([
        'household_id' => $otherHousehold->id,
        'account_id' => $otherAccount->id,
        'category_id' => $this->category->id,
        'amount' => -5000,
        'date' => '2026-03-11',
        'created_by_user_id' => $otherUser->id,
    ]);
    $foreignCategory = Category::factory()->create([
        'household_id' => $otherHousehold->id,
        'name' => 'Foreign Destination',
        'starting_month' => '2026-03-01',
    ]);
    CategoryBudgetMove::query()->create([
        'household_id' => $otherHousehold->id,
        'from_category_id' => $this->category->id,
        'to_category_id' => $foreignCategory->id,
        'effective_month' => '2026-03-01',
        'amount' => 400,
        'created_by_user_id' => $otherUser->id,
    ]);

    expect($this->budget->availableForMonth($this->category, 2026, 3))->toBe(15000);
});
