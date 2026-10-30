<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetMove;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryGroup;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Database\Seeders\CategoryGroupSeeder;

test('account factory produces a valid account with ULID and integer cents', function () {
    $account = Account::factory()->create();

    expect($account->id)->toBeString()->toHaveLength(26)
        ->and($account->starting_balance)->toBeInt()
        ->and($account->starting_balance_date)->not->toBeNull()
        ->and($account->archived)->toBeBool()
        ->and($account->household_id)->not->toBeEmpty();
});

test('category factory produces a valid category in a group', function () {
    $category = Category::factory()->create();

    expect($category->id)->toBeString()->toHaveLength(26)
        ->and($category->name)->not->toBeEmpty()
        ->and($category->direction)->toBe('expense')
        ->and($category->group)->toBeInstanceOf(CategoryGroup::class)
        ->and($category->household_id)->not->toBeEmpty();
});

test('category groups seed transfer and adjustment categories together once per household', function () {
    $household = Household::factory()->create();
    $seeder = new CategoryGroupSeeder;

    $seeder->run($household);
    $seeder->run($household);

    $categories = $household->categories()->whereIn('name', [
        'Transfer',
        'Credit Card Payment',
        'Balance Adjustments',
    ])->get()->keyBy('name');

    expect($categories)->toHaveCount(3)
        ->and($categories['Transfer']->category_group_id)->toBe($categories['Credit Card Payment']->category_group_id)
        ->and($categories['Transfer']->category_group_id)->toBe($categories['Balance Adjustments']->category_group_id)
        ->and($categories['Transfer']->direction)->toBe('transfer')
        ->and($categories['Credit Card Payment']->direction)->toBe('transfer')
        ->and($categories['Balance Adjustments']->direction)->toBe('adjustment')
        ->and($categories['Transfer']->icon)->toBe('🔁')
        ->and($household->categories()->where('name', 'Transfer')->count())->toBe(1);
});

test('category budget plans record effective-dated amounts', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['household_id' => $user->household()->id]);

    $plan = CategoryBudgetPlan::query()->create([
        'category_id' => $category->id,
        'effective_month' => '2026-07-01',
        'planned_amount' => 30000,
        'created_by_user_id' => $user->id,
    ]);

    expect($plan->id)->toBeString()->toHaveLength(26)
        ->and($plan->planned_amount)->toBe(30000)
        ->and($category->budgetPlans)->toHaveCount(1);
});

test('category budget moves carry household ownership and cents', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $from = Category::factory()->create(['household_id' => $household->id]);
    $to = Category::factory()->create(['household_id' => $household->id]);

    $move = CategoryBudgetMove::query()->create([
        'household_id' => $household->id,
        'from_category_id' => $from->id,
        'to_category_id' => $to->id,
        'effective_month' => '2026-07-01',
        'amount' => 15000,
        'created_by_user_id' => $user->id,
    ]);

    expect($move->id)->toBeString()->toHaveLength(26)
        ->and($move->amount)->toBe(15000)
        ->and($move->from_category_id)->toBe($from->id)
        ->and($move->to_category_id)->toBe($to->id)
        ->and($move->household_id)->toBe($household->id);
});

test('transaction belongs to an account and category', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->account)->toBeInstanceOf(Account::class)
        ->and($transaction->createdBy)->toBeInstanceOf(User::class)
        ->and($transaction->category)->toBeInstanceOf(Category::class);
});

test('split transaction children sum to parent amount', function () {
    $parent = Transaction::factory()->create([
        'amount' => -10000,
        'is_split' => true,
        'category_id' => null,
    ]);
    $firstCategory = Category::factory()->create(['household_id' => $parent->household_id]);
    $secondCategory = Category::factory()->create(['household_id' => $parent->household_id]);

    TransactionSplit::factory()->create([
        'transaction_id' => $parent->id,
        'category_id' => $firstCategory->id,
        'amount' => -7500,
    ]);
    TransactionSplit::factory()->create([
        'transaction_id' => $parent->id,
        'category_id' => $secondCategory->id,
        'amount' => -2500,
    ]);

    $parent->refresh()->load('splits');

    expect($parent->splits)->toHaveCount(2)
        ->and($parent->splits->sum('amount'))->toBe($parent->amount);
});

test('transaction splits cascade-delete when their parent is deleted', function () {
    $parent = Transaction::factory()->create(['is_split' => true]);
    TransactionSplit::factory()->create(['transaction_id' => $parent->id]);
    TransactionSplit::factory()->create(['transaction_id' => $parent->id]);
    $splitIds = $parent->splits()->pluck('id');

    $parent->delete();

    expect(TransactionSplit::whereIn('id', $splitIds)->count())->toBe(0);
});
