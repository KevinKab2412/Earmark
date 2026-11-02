<?php

use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('creates a save-up goal linked to a category', function () {
    $user = actingAsOwner();
    $category = $user->household()->categories()->where('name', 'Vacation')->firstOrFail();

    visit('/household/goals')
        ->click('@open-create-goal')
        ->fill('goal-name', 'Trip to Japan')
        ->fill('goal-target', '5000')
        ->select('#goal-category', $category->id)
        ->click('@submit-goal')
        ->assertSee('Trip to Japan')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('goals', [
        'name' => 'Trip to Japan',
        'type' => 'save_up',
        'linked_category_id' => $category->id,
        'target_amount' => 500000,
    ]);
});

it('edits, archives, and restores a goal with currency inputs', function () {
    $user = actingAsOwner();
    $category = $user->household()->categories()->where('name', 'Vacation')->firstOrFail();

    visit('/household/goals')
        ->click('@open-create-goal')
        ->fill('goal-name', 'Trip to Japan')
        ->fill('goal-target', '5000')
        ->select('#goal-category', $category->id)
        ->click('@submit-goal')
        ->click('@edit-goal')
        ->fill('goal-name', 'Japan trip')
        ->fill('goal-target', '6000')
        ->click('@submit-goal')
        ->click('@archive-goal')
        ->click('@show-archived-goals')
        ->assertSee('Japan trip')
        ->click('@restore-goal')
        ->click('@show-active-goals')
        ->assertSee('Japan trip')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('goals', [
        'name' => 'Japan trip',
        'type' => 'save_up',
        'linked_category_id' => $category->id,
        'target_amount' => 600000,
        'status' => 'active',
    ]);
});

function assertGoalsPixelBaseline(int $width, int $height): void
{
    test()->travelTo('2026-11-01 12:00:00');
    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $household = $user->household();
    $category = $household->categories()->where('name', 'Emergency Fund')->firstOrFail();
    $month = now()->startOfMonth()->toDateString();

    $category->budgetPlans()
        ->whereDate('effective_month', $month)
        ->firstOrFail()
        ->update(['planned_amount' => 250000]);

    Goal::factory()->create([
        'household_id' => $household->id,
        'created_by_user_id' => $user->id,
        'name' => 'Emergency Fund',
        'type' => 'save_up',
        'target_amount' => 1000000,
        'target_date' => '2027-11-01',
        'linked_category_id' => $category->id,
    ]);
    createPixelBaselineDebtGoal($user);

    test()->actingAs($user)
        ->get(route('household.goals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('goals.0.progress.current', '$2,500.00')
        );

    visit('/household/goals')
        ->resize($width, $height)
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches();
}

function createPixelBaselineDebtGoal(User $user): void
{
    $household = $user->household();
    $account = Account::factory()->create([
        'household_id' => $household->id,
        'name' => 'Rewards Visa',
        'type' => 'credit_card',
        'starting_balance' => -200000,
    ]);
    $paymentCategory = $household->categories()->where('name', 'Credit Card Payment')->firstOrFail();

    Goal::factory()->create([
        'household_id' => $household->id,
        'created_by_user_id' => $user->id,
        'name' => 'Pay off Rewards Visa',
        'type' => 'pay_down',
        'target_amount' => 0,
        'apr_bps' => 1999,
        'required_payment' => 20000,
        'linked_account_id' => $account->id,
        'payment_category_id' => $paymentCategory->id,
    ]);
}

it('pixel-diffs the goals page at 390 pixels', function () {
    assertGoalsPixelBaseline(390, 844);
});

it('pixel-diffs the goals page at 768 pixels', function () {
    assertGoalsPixelBaseline(768, 1024);
});

it('pixel-diffs the goals page at 1440 pixels', function () {
    assertGoalsPixelBaseline(1440, 1000);
});

it('pixel-diffs the add goal dialog', function () {
    test()->travelTo('2026-11-01 12:00:00');
    actingAsOwner(['name' => 'Pixel Baseline']);

    visit('/household/goals')
        ->resize(1440, 1000)
        ->click('@open-create-goal')
        ->assertSee('Savings progress follows the linked expense category.')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches(false);
});

it('creates a debt goal with a credit card payment category', function () {
    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $account = Account::factory()->create([
        'household_id' => $user->household()->id,
        'name' => 'Rewards Visa',
        'type' => 'credit_card',
        'starting_balance' => -200000,
    ]);
    $paymentCategory = $user->household()->categories()->where('name', 'Credit Card Payment')->firstOrFail();

    visit('/household/goals')
        ->resize(1440, 1000)
        ->click('@open-create-goal')
        ->fill('goal-name', 'Pay off Rewards Visa')
        ->select('#goal-type', 'pay_down')
        ->fill('#goal-apr', '19.99')
        ->fill('#goal-payment', '200.00')
        ->select('#goal-debt-source', 'account')
        ->select('#goal-debt-account', $account->id)
        ->select('#goal-payment-category', $paymentCategory->id)
        ->assertScript('document.querySelector("#goal-payment-category").selectedOptions[0].textContent.trim() === "Credit Card Payment · transfer"')
        ->assertSee('Transfer payment categories track payments without counting them as expenses.')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches(false)
        ->click('@submit-goal')
        ->assertSee('Pay off Rewards Visa')
        ->assertSee('Credit Card Payment')
        ->assertSee('19.99%')
        ->assertNoJavaScriptErrors();

    $goal = Goal::query()->where('name', 'Pay off Rewards Visa')->sole();
    expect($goal->linked_account_id)->toBe($account->id)
        ->and($goal->payment_category_id)->toBe($paymentCategory->id)
        ->and($goal->apr_bps)->toBe(1999)
        ->and($goal->required_payment)->toBe(20000);
});
