<?php

use App\Models\CategoryBudgetMove;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryGroup;
use App\Models\CategoryRolloverSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('a new household gets fresh category defaults instead of bucket plans', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $month = now()->startOfMonth()->toDateString();

    expect($household->categoryGroups()->orderBy('sort_order')->pluck('name')->all())->toBe([
        'Income',
        'Housing',
        'Transportation',
        'Food & Dining',
        'Lifestyle',
        'Health & Wellness',
        'Debt',
        'Savings Goals',
        'Bills',
        'Other',
        'Transfers',
    ]);

    $transfers = $household->categories()
        ->whereHas('group', fn ($query) => $query->where('name', 'Transfers'))
        ->orderBy('sort_order')
        ->get();

    expect($transfers->pluck('name')->all())->toBe([
        'Transfer',
        'Credit Card Payment',
        'Balance Adjustments',
    ])
        ->and($transfers[0]->direction)->toBe('transfer')
        ->and($transfers[1]->direction)->toBe('transfer')
        ->and($transfers[2]->direction)->toBe('adjustment')
        ->and($transfers[0]->icon)->toBe('🔁')
        ->and($transfers[1]->icon)->toBe('💳')
        ->and($transfers[2]->icon)->toBe('⚖️');

    expect(Schema::hasColumn('category_groups', 'planned_amount'))->toBeFalse()
        ->and(Schema::hasTable('buckets'))->toBeFalse()
        ->and(Schema::hasColumn('transactions', 'bucket_id'))->toBeFalse()
        ->and(CategoryBudgetPlan::query()->whereIn('category_id', CategoryGroup::query()->pluck('id'))->count())->toBe(0)
        ->and(CategoryBudgetMove::query()->count())->toBe(0)
        ->and(CategoryRolloverSetting::query()->whereIn('category_id', CategoryGroup::query()->pluck('id'))->count())->toBe(0);

    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();

    expect($groceries->direction)->toBe('expense')
        ->and($groceries->starting_balance)->toBe(0)
        ->and($groceries->starting_month->toDateString())->toBe($month)
        ->and((int) $groceries->budgetPlans()->whereDate('effective_month', $month)->value('planned_amount'))->toBe(0);

    $transfer = $transfers[0];

    expect($transfer->budgetPlans()->count())->toBe(0)
        ->and(in_array($transfer->direction, ['income', 'expense'], true))->toBeFalse();

    $emergencyFund = $household->categories()->where('name', 'Emergency Fund')->firstOrFail();

    expect((bool) $emergencyFund->rolloverSettings()->whereDate('effective_month', $month)->value('enabled'))->toBeTrue();

    $otherHousehold = User::factory()->create()->household();

    expect($otherHousehold->categoryGroups()->count())->toBe(11)
        ->and($otherHousehold->categories()->where('name', 'Groceries')->value('id'))->not->toBe($groceries->id);
});
