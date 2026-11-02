<?php

use App\Enums\PositionClassification;
use App\Enums\PositionPurpose;
use App\Http\Controllers\Household\DashboardController;
use App\Models\Account;
use App\Models\Category;
use App\Models\FinancialPosition;
use App\Models\Goal;
use App\Models\Holding;
use App\Models\RecurringSchedule;
use App\Models\Transaction;
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
                'net_worth',
                'goals',
                'spending',
                'transactions',
                'recurring',
                'investments',
            ])
            ->has('enabledCards')
            ->has('cards.budget.planned')
            ->has('cards.budget.available')
            ->has('cards.budget.over_budget')
            ->missing('cards.top_categories')
            ->missing('cards.review_queue')
            ->has('cards.recurring.count')
            ->has('cards.goals.count')
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
            ->has('cards.spending.series', 1)
            ->where('cards.spending.series.0.name', 'Groceries')
            ->where('cards.spending.series.0.spent', '$150.00')
            ->where('cards.spending.total', '$150.00')
            ->has('cards.transactions.items', 1)
            ->where('cards.transactions.items.0.payee', 'Grocery Store')
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

test('the spending series ranks this month\'s expenses and ignores income, transfers, refunds, and earlier months', function () {
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

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cards.spending.series', 2)
            ->where('cards.spending.series.0.name', 'Test Rent')
            ->where('cards.spending.series.0.spent', '$1,500.00')
            ->where('cards.spending.series.1.name', 'Groceries')
            ->where('cards.spending.series.1.spent', '$50.00')
            ->where('cards.spending.total', '$1,550.00')
            ->missing('cards.top_categories')
        );
});

test('the spending series is empty when the month has no expenses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards.spending.series', [])
            ->where('cards.spending.total', '$0.00')
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
