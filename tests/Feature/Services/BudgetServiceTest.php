<?php

use App\Actions\Categories\SetCategoryBudgetPlan;
use App\Actions\Categories\SetCategoryRollover;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetMove;
use App\Models\CategoryBudgetPlan;
use App\Models\CategoryRolloverSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryBudgetService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    $this->user = User::factory()->create();
    $this->household = $this->user->household();
    $this->account = Account::factory()->create(['household_id' => $this->household->id]);
    $this->category = Category::factory()->create([
        'household_id' => $this->household->id,
        'name' => 'Custom Food',
        'created_at' => '2026-01-01 00:00:00',
    ]);
    $this->service = app(CategoryBudgetService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('a plan amount applies from its effective month until the next change', function () {
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-01-01',
        'planned_amount' => 10000,
    ]);

    app(SetCategoryBudgetPlan::class)->handle($this->category, 25000, 2026, 4, false, $this->user);

    expect($this->service->planForMonth($this->category, 2026, 3))->toBe(10000)
        ->and($this->service->planForMonth($this->category, 2026, 4))->toBe(25000)
        ->and($this->service->planForMonth($this->category, 2026, 5))->toBe(10000);
});

test('future plan changes keep the new amount for every later month', function () {
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-01-01',
        'planned_amount' => 10000,
    ]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-06-01',
        'planned_amount' => 15000,
    ]);

    app(SetCategoryBudgetPlan::class)->handle($this->category, 20000, 2026, 3, true, $this->user);

    expect($this->service->planForMonth($this->category, 2026, 2))->toBe(10000)
        ->and($this->service->planForMonth($this->category, 2026, 3))->toBe(20000)
        ->and($this->service->planForMonth($this->category, 2026, 8))->toBe(20000);
});

test('rollover carries both positive and negative category balances', function () {
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-01-01',
        'planned_amount' => 10000,
    ]);
    CategoryRolloverSetting::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-01-01',
        'enabled' => true,
    ]);
    Transaction::factory()->create([
        'household_id' => $this->household->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'amount' => -12000,
        'date' => '2026-01-15',
        'created_by_user_id' => $this->user->id,
    ]);

    expect($this->service->availableForMonth($this->category, 2026, 1))->toBe(-2000)
        ->and($this->service->summaryForMonth($this->category, 2026, 2)['carryover'])->toBe(-2000)
        ->and($this->service->availableForMonth($this->category, 2026, 2))->toBe(8000);

    app(SetCategoryRollover::class)->handle($this->category, false, 2026, 3, $this->user);

    expect($this->service->summaryForMonth($this->category, 2026, 3)['carryover'])->toBe(8000)
        ->and($this->service->summaryForMonth($this->category, 2026, 4)['carryover'])->toBe(0);
});

test('moves reduce one category and increase another by the same amount', function () {
    $destination = Category::factory()->create([
        'household_id' => $this->household->id,
        'name' => 'Custom Travel',
        'created_at' => '2026-01-01 00:00:00',
    ]);
    CategoryBudgetPlan::query()->create([
        'category_id' => $this->category->id,
        'effective_month' => '2026-01-01',
        'planned_amount' => 5000,
    ]);
    CategoryBudgetMove::query()->create([
        'household_id' => $this->household->id,
        'from_category_id' => $this->category->id,
        'to_category_id' => $destination->id,
        'effective_month' => '2026-01-01',
        'amount' => 2000,
        'created_by_user_id' => $this->user->id,
    ]);

    expect($this->service->availableForMonth($this->category, 2026, 1))->toBe(3000)
        ->and($this->service->availableForMonth($destination, 2026, 1))->toBe(2000)
        ->and($this->service->availableForMonth($this->category, 2026, 1) + $this->service->availableForMonth($destination, 2026, 1))->toBe(5000);
});
