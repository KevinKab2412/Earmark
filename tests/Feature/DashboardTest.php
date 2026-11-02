<?php

use App\Enums\PositionClassification;
use App\Enums\PositionPurpose;
use App\Http\Controllers\Household\DashboardController;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryBudgetPlan;
use App\Models\FinancialPosition;
use App\Models\Goal;
use App\Models\Holding;
use App\Models\RecurringSchedule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the overview', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('household/Overview')
            ->where('availableCards', [
                'budget',
                'spending',
                'transactions',
                'recurring',
                'net_worth',
                'investments',
                'goals',
            ])
            ->has('enabledCards')
            ->has('cards.budget.planned')
            ->has('cards.budget.available')
            ->has('cards.budget.over_budget')
            ->has('cards.budget.groups', 2)
            ->missing('cards.top_categories')
            ->missing('cards.review_queue')
            ->has('cards.recurring.count')
            ->has('cards.goals.count')
            ->has('cards.goals.items')
            ->has('cards.net_worth.investable')
            ->has('cards.net_worth.total')
            ->has('cards.net_worth.freshness')
            ->has('cards.investments.total')
            ->missing('cards.scenarios')
            ->has('cards.spending')
            ->has('cards.transactions.items', 0)
        );
});

test('overview totals stay the controller budget, net worth, goals, recurring, and holdings figures', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id]);
    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'payee' => 'Grocery Store',
        'amount' => -15000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    $position = FinancialPosition::factory()->create([
        'household_id' => $household->id,
        'purpose' => PositionPurpose::Investable,
        'classification' => PositionClassification::Asset,
    ]);
    Valuation::factory()->create([
        'financial_position_id' => $position->id,
        'amount' => 250_000,
        'valued_at' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Goal::factory()->count(2)->create([
        'household_id' => $household->id,
        'status' => 'active',
        'created_by_user_id' => $user->id,
    ]);
    RecurringSchedule::factory()->create([
        'household_id' => $household->id,
        'status' => 'confirmed',
        'created_by_user_id' => $user->id,
    ]);
    Holding::factory()->create([
        'household_id' => $household->id,
        'market_value_cents' => 80_000,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards.budget.planned', '$0.00')
            ->where('cards.budget.available', '-$150.00')
            ->has('cards.budget.over_budget', 1)
            ->where('cards.budget.over_budget.0.name', 'Groceries')
            ->where('cards.budget.over_budget.0.available', '-$150.00')
            ->where('cards.net_worth.investable', '$2,500.00')
            ->where('cards.net_worth.total', '$2,500.00')
            ->where('cards.goals.count', 2)
            ->where('cards.recurring.count', 1)
            ->where('cards.investments.total', '$800.00')
            ->where('cards.investments.count', 1)
            ->where('cards.spending.current_total', '$150.00')
            ->where('cards.spending.previous_total', '$0.00')
            ->where('cards.spending.current_series.'.(now()->day - 1).'.amount', 15000)
            ->has('cards.transactions.items', 1)
            ->where('cards.transactions.items.0.payee', 'Grocery Store')
        );
});

test('overview shows income and expense budget progress with savings and debt goal balances', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id]);
    $income = $household->categories()->where('name', 'Paychecks')->firstOrFail();
    $expense = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $savings = Category::factory()->create([
        'household_id' => $household->id,
        'name' => 'Emergency fund',
    ]);

    CategoryBudgetPlan::query()
        ->where('category_id', $income->id)
        ->whereDate('effective_month', now()->startOfMonth()->toDateString())
        ->update(['planned_amount' => 50000]);
    CategoryBudgetPlan::query()
        ->where('category_id', $expense->id)
        ->whereDate('effective_month', now()->startOfMonth()->toDateString())
        ->update(['planned_amount' => 30000]);
    CategoryBudgetPlan::factory()->create([
        'category_id' => $savings->id,
        'effective_month' => now()->startOfMonth()->toDateString(),
        'planned_amount' => 100000,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $income->id,
        'amount' => 20000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $expense->id,
        'amount' => -8000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Goal::factory()->create([
        'household_id' => $household->id,
        'name' => 'Emergency fund',
        'type' => 'save_up',
        'target_amount' => 250000,
        'linked_category_id' => $savings->id,
        'created_by_user_id' => $user->id,
    ]);
    $debt = FinancialPosition::factory()->create([
        'household_id' => $household->id,
        'classification' => PositionClassification::Liability,
    ]);
    Valuation::factory()->create([
        'financial_position_id' => $debt->id,
        'amount' => -600000,
        'valued_at' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Goal::factory()->create([
        'household_id' => $household->id,
        'name' => 'Pay off card',
        'type' => 'pay_down',
        'target_amount' => 600000,
        'linked_position_id' => $debt->id,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards.budget.groups.0.label', 'Income')
            ->where('cards.budget.groups.0.planned', '$500.00')
            ->where('cards.budget.groups.0.activity', '$200.00')
            ->where('cards.budget.groups.0.remaining', '$300.00')
            ->where('cards.budget.groups.1.label', 'Expenses')
            ->where('cards.budget.groups.1.planned', '$1,300.00')
            ->where('cards.budget.groups.1.activity', '$80.00')
            ->where('cards.budget.groups.1.remaining', '$1,220.00')
            ->has('cards.goals.items', 2)
            ->where('cards.goals.items.0.current', '$1,000.00')
            ->where('cards.goals.items.0.target', '$2,500.00')
            ->where('cards.goals.items.0.progress', 40)
            ->where('cards.goals.items.1.current', '$6,000.00')
            ->where('cards.goals.items.1.progress', 0)
        );
});

test('the overview transaction preview lists recent categorized activity and omits paired transfers', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id]);
    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $transferCategory = $household->categories()->where('name', 'Transfer')->firstOrFail();

    $older = Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'date' => now()->subDay()->toDateString(),
        'payee' => 'Yesterday market',
        'amount' => -2500,
        'created_by_user_id' => $user->id,
    ]);
    $newer = Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'date' => now()->toDateString(),
        'payee' => 'Today market',
        'amount' => -4200,
        'created_by_user_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $transferCategory->id,
        'date' => now()->toDateString(),
        'payee' => 'Card payment',
        'amount' => -5000,
        'transfer_pair_id' => (string) Str::ulid(),
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cards.transactions.items', 2)
            ->where('cards.transactions.items.0.id', $newer->id)
            ->where('cards.transactions.items.0.payee', 'Today market')
            ->where('cards.transactions.items.0.category', 'Groceries')
            ->where('cards.transactions.items.0.account', $account->name)
            ->where('cards.transactions.items.0.amount', '-$42.00')
            ->where('cards.transactions.items.1.id', $older->id)
            ->where('cards.transactions.items.1.date', now()->subDay()->format('M j'))
        );
});

test('the spending chart compares monthly expense totals and excludes income, transfers, refunds, and excluded rows', function () {
    $this->travelTo(now()->setDay(15));

    $user = User::factory()->create();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id]);
    $groceries = $household->categories()->where('name', 'Groceries')->firstOrFail();
    $rent = Category::factory()->create([
        'household_id' => $household->id,
        'name' => 'Test Rent',
    ]);
    $paychecks = $household->categories()->where('name', 'Paychecks')->firstOrFail();
    $restaurants = $household->categories()->where('name', 'Restaurants')->firstOrFail();

    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => -5000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $rent->id,
        'amount' => -150000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    $splitTransaction = Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -40000,
        'is_split' => true,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    TransactionSplit::factory()->create([
        'transaction_id' => $splitTransaction->id,
        'category_id' => $groceries->id,
        'amount' => -30000,
    ]);
    TransactionSplit::factory()->create([
        'transaction_id' => $splitTransaction->id,
        'category_id' => $rent->id,
        'amount' => -10000,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $paychecks->id,
        'amount' => 250000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => -100000,
        'date' => now()->toDateString(),
        'transfer_pair_id' => (string) Str::ulid(),
        'created_by_user_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $restaurants->id,
        'amount' => 3000,
        'date' => now()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $rent->id,
        'amount' => -500000,
        'date' => now()->subMonth()->startOfMonth()->toDateString(),
        'created_by_user_id' => $user->id,
    ]);
    Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'category_id' => $rent->id,
        'amount' => -900000,
        'date' => now()->toDateString(),
        'excluded_from_reports' => true,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards.spending.current_total', '$1,950.00')
            ->where('cards.spending.previous_total', '$5,000.00')
            ->where('cards.spending.current_series.14.amount', 195000)
            ->where('cards.spending.previous_series.30.amount', 500000)
            ->missing('cards.top_categories')
        );
});

test('the spending series is empty when the month has no expenses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards.spending.current_total', '$0.00')
            ->where('cards.spending.previous_total', '$0.00')
            ->has('cards.spending.current_series')
            ->has('cards.spending.previous_series')
            ->missing('cards.top_categories')
        );
});

test('every card is enabled by default', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabledCards', DashboardController::CARDS)
        );
});

test('members can choose which overview cards to show', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('dashboard.cards'), ['cards' => ['budget', 'goals']])
        ->assertRedirect();

    expect($user->household()->fresh()->overview_cards)->toBe(['budget', 'goals']);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabledCards', ['budget', 'goals'])
        );
});

test('overview card order is the saved list', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $order = [
        'spending',
        'budget',
        'net_worth',
        'goals',
        'transactions',
        'recurring',
        'investments',
    ];

    $this->post(route('dashboard.cards'), ['cards' => $order])
        ->assertRedirect();

    expect($user->household()->fresh()->overview_cards)->toBe($order);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabledCards', $order)
        );
});

test('overview card preferences reject unknown cards', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('dashboard.cards'), ['cards' => ['budget', 'review_queue']])
        ->assertSessionHasErrors('cards.1');

    expect($user->household()->fresh()->overview_cards)->toBeNull();
});

test('retired overview card keys stay stored and drop out of the page list', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $stored = ['goals', 'review_queue', 'budget', 'scenarios'];
    $household->update(['overview_cards' => $stored]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabledCards', ['goals', 'budget'])
        );

    expect($household->fresh()->overview_cards)->toBe($stored);
});

test('overview cards fall back to the current set when every saved key is retired', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $stored = ['review_queue', 'scenarios', 'top_categories'];
    $household->update(['overview_cards' => $stored]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabledCards', DashboardController::CARDS)
        );

    expect($household->fresh()->overview_cards)->toBe($stored);
});
