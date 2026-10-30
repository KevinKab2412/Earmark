<?php

use App\Actions\Transactions\TransferFunds;
use App\Models\Account;
use App\Models\Category;
use App\Models\SavedReport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryBudgetService;
use App\Services\Reporting\ReportingService;
use Illuminate\Support\Str;

function reportTxn(User $user, array $attributes = []): Transaction
{
    $household = $user->household();

    return Transaction::factory()->create(array_merge([
        'household_id' => $household->id,
        'account_id' => Account::factory()->create(['household_id' => $household->id])->id,
        'category_id' => null,
        'transfer_pair_id' => null,
        'excluded_from_reports' => false,
        'created_by_user_id' => $user->id,
    ], $attributes));
}

test('cash flow totals reconcile and exclude hidden rows and transfers', function () {
    $user = User::factory()->create();
    reportTxn($user, ['amount' => 10000, 'date' => '2026-06-01']);
    reportTxn($user, ['amount' => -3000, 'date' => '2026-06-02']);
    reportTxn($user, ['amount' => -9999, 'date' => '2026-06-03', 'excluded_from_reports' => true]);
    reportTxn($user, ['amount' => -5000, 'date' => '2026-06-04', 'transfer_pair_id' => Str::ulid()]);

    $cashFlow = app(ReportingService::class)->cashFlow($user->household(), ['from' => '2026-06-01', 'to' => '2026-06-30']);

    expect($cashFlow['income_cents'])->toBe(10000)
        ->and($cashFlow['expense_cents'])->toBe(-3000)
        ->and($cashFlow['net_cents'])->toBe(7000);
});

test('credit card payments stay out of expense reports and category activity', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $card = Account::factory()->create(['household_id' => $household->id, 'type' => 'credit_card']);
    $checking = Account::factory()->create(['household_id' => $household->id]);
    $food = Category::factory()->create(['household_id' => $household->id, 'name' => 'Card Purchase']);
    $food->forceFill(['created_at' => '2026-06-01 00:00:00'])->saveQuietly();
    $paymentCategory = Category::query()
        ->where('household_id', $household->id)
        ->where('name', 'Credit Card Payment')
        ->sole();

    reportTxn($user, [
        'account_id' => $card->id,
        'amount' => -12000,
        'date' => '2026-06-10',
        'category_id' => $food->id,
    ]);
    reportTxn($user, [
        'account_id' => $checking->id,
        'amount' => -12000,
        'date' => '2026-06-19',
        'category_id' => $paymentCategory->id,
    ]);
    TransferFunds::run([
        'date' => '2026-06-20',
        'from_account_id' => $checking->id,
        'to_account_id' => $card->id,
        'category_id' => $paymentCategory->id,
        'amount' => 12000,
        'memo' => null,
    ], $user, $household);

    $filters = ['from' => '2026-06-01', 'to' => '2026-06-30'];
    $cashFlow = app(ReportingService::class)->cashFlow($household, $filters);
    $category = app(CategoryBudgetService::class)->summaryForMonth($food, 2026, 6);
    $paymentCategoryActivity = app(CategoryBudgetService::class)->summaryForMonth($paymentCategory, 2026, 6);

    expect($cashFlow['expense_cents'])->toBe(-12000)
        ->and($category['activity'])->toBe(-12000)
        ->and($paymentCategoryActivity['activity'])->toBe(0)
        ->and(Transaction::query()->whereNotNull('transfer_pair_id')->count())->toBe(2);
});

test('spending by category reconciles to the filtered rows', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $food = Category::factory()->create(['household_id' => $household->id, 'name' => 'Food']);
    $gas = Category::factory()->create(['household_id' => $household->id, 'name' => 'Test Fuel']);
    reportTxn($user, ['amount' => -6000, 'category_id' => $food->id, 'date' => '2026-06-01']);
    reportTxn($user, ['amount' => -2000, 'category_id' => $food->id, 'date' => '2026-06-02']);
    reportTxn($user, ['amount' => -4000, 'category_id' => $gas->id, 'date' => '2026-06-03']);

    $rows = collect(app(ReportingService::class)->byCategory($household, [], 'expense'));

    expect((int) $rows->sum('total_cents'))->toBe(-12000)
        ->and($rows->firstWhere('category', 'Food')['total_cents'])->toBe(-8000);
});

test('a report can be exported as CSV', function () {
    $user = User::factory()->create();
    reportTxn($user, ['amount' => -3000, 'payee' => 'Loblaws', 'date' => '2026-06-01']);

    $response = $this->actingAs($user)->get(route('household.reports.export', ['type' => 'cash_flow']));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->getContent())->toContain('Loblaws');
});

test('a saved report can be created and reopened', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('household.reports.store'), [
        'name' => 'Q2 spending',
        'type' => 'spending',
        'filters' => ['from' => '2026-04-01', 'to' => '2026-06-30'],
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('household.reports.index'))
        ->assertInertia(fn ($page) => $page->component('household/Reports')->has('savedReports', 1));
});

test('the budget report reconciles to the budget service', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('household.reports.index', ['type' => 'budget']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('type', 'budget')->has('result.rows'));
});

test('a user cannot delete another household saved report', function () {
    $user = User::factory()->create();
    $other = SavedReport::factory()->create();

    $this->actingAs($user)->delete(route('household.reports.destroy', $other))->assertNotFound();
});
