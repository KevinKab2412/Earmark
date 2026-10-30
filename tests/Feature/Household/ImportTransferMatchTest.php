<?php

use App\Models\Account;
use App\Models\Household;
use App\Models\StagedTransaction;
use App\Models\StatementUpload;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transferReviewRow(StagedTransaction $row, array $overrides = []): array
{
    return array_merge([
        'id' => $row->id,
        'date' => $row->date->toDateString(),
        'payee' => $row->payee,
        'amount' => $row->amount,
        'final_category_id' => $row->final_category_id,
        'accept' => true,
        'status' => 'pending',
        'splits' => [],
    ], $overrides);
}

/**
 * @return array{user: User, household: Household, checking: Account, savings: Account, checkingUpload: StatementUpload, savingsUpload: StatementUpload, outflow: StagedTransaction, inflow: StagedTransaction}
 */
function oppositeStagedTransfer(): array
{
    $user = User::factory()->create();
    $household = $user->household();
    $checking = Account::factory()->create(['household_id' => $household->id, 'name' => 'Chequing']);
    $savings = Account::factory()->create(['household_id' => $household->id, 'name' => 'Savings']);
    $checkingUpload = StatementUpload::factory()->create([
        'household_id' => $household->id,
        'account_id' => $checking->id,
        'uploaded_by_user_id' => $user->id,
        'source' => 'csv',
    ]);
    $savingsUpload = StatementUpload::factory()->create([
        'household_id' => $household->id,
        'account_id' => $savings->id,
        'uploaded_by_user_id' => $user->id,
        'source' => 'csv',
    ]);
    $outflow = StagedTransaction::factory()->create([
        'statement_upload_id' => $checkingUpload->id,
        'date' => '2026-06-01',
        'payee' => 'Transfer to savings',
        'amount' => -50000,
        'accept' => true,
        'status' => 'pending',
    ]);
    $inflow = StagedTransaction::factory()->create([
        'statement_upload_id' => $savingsUpload->id,
        'date' => '2026-06-04',
        'payee' => 'Transfer from chequing',
        'amount' => 50000,
        'accept' => true,
        'status' => 'pending',
    ]);

    return compact(
        'user',
        'household',
        'checking',
        'savings',
        'checkingUpload',
        'savingsUpload',
        'outflow',
        'inflow',
    );
}

test('a transfer suggestion stays unpaired and near misses are not paired', function () {
    [
        'user' => $user,
        'household' => $household,
        'checking' => $checking,
        'savings' => $savings,
        'checkingUpload' => $checkingUpload,
        'savingsUpload' => $savingsUpload,
        'outflow' => $outflow,
        'inflow' => $inflow,
    ] = oppositeStagedTransfer();

    StagedTransaction::factory()->create([
        'statement_upload_id' => $checkingUpload->id,
        'date' => '2026-06-01',
        'payee' => 'Same account',
        'amount' => 50000,
        'accept' => true,
        'status' => 'pending',
    ]);
    StagedTransaction::factory()->create([
        'statement_upload_id' => $savingsUpload->id,
        'date' => '2026-06-01',
        'payee' => 'Wrong amount',
        'amount' => 40000,
        'accept' => true,
        'status' => 'pending',
    ]);
    StagedTransaction::factory()->create([
        'statement_upload_id' => $savingsUpload->id,
        'date' => '2026-06-01',
        'payee' => 'Same sign',
        'amount' => -50000,
        'accept' => true,
        'status' => 'pending',
    ]);
    StagedTransaction::factory()->create([
        'statement_upload_id' => $savingsUpload->id,
        'date' => '2026-06-05',
        'payee' => 'Outside window',
        'amount' => 50000,
        'accept' => true,
        'status' => 'pending',
    ]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['household_id' => $other->household()->id, 'name' => 'Other']);
    $otherUpload = StatementUpload::factory()->create([
        'household_id' => $other->household()->id,
        'account_id' => $otherAccount->id,
        'uploaded_by_user_id' => $other->id,
    ]);
    StagedTransaction::factory()->create([
        'statement_upload_id' => $otherUpload->id,
        'date' => '2026-06-02',
        'payee' => 'Other household',
        'amount' => 50000,
        'accept' => true,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->get(route('household.import.review', $checkingUpload))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('household/ImportReview')
            ->has('rows', 2)
            ->where('rows', function (Collection $rows) use ($outflow, $inflow): bool {
                $row = $rows->firstWhere('id', $outflow->id);
                $matches = collect($row['transfer_matches'] ?? []);
                $match = $matches->first();

                return $row['transfer_pair_id'] === null
                    && $matches->count() === 1
                    && $match['id'] === $inflow->id
                    && $match['type'] === 'staged'
                    && $match['amount'] === 50000
                    && $match['days_apart'] === 3
                    && $match['account'] === 'Savings';
            })
        );

    expect(StagedTransaction::query()->whereNotNull('transfer_pair_id')->count())->toBe(0);

    $this->actingAs($user)->post(route('household.import.promote', $checkingUpload))->assertRedirect();
    $this->actingAs($user)->post(route('household.import.promote', $savingsUpload))->assertRedirect();

    expect(Transaction::query()->where('household_id', $household->id)->count())->toBe(6)
        ->and(Transaction::query()->where('household_id', $household->id)->whereNotNull('transfer_pair_id')->count())->toBe(0)
        ->and(Transaction::query()->where('account_id', $checking->id)->where('amount', -50000)->value('transfer_pair_id'))->toBeNull()
        ->and(Transaction::query()->where('account_id', $savings->id)->where('amount', 50000)->whereDate('date', '2026-06-04')->value('transfer_pair_id'))->toBeNull();
});

test('confirming a transfer suggestion pairs both promoted ledger transactions', function () {
    [
        'user' => $user,
        'household' => $household,
        'checkingUpload' => $checkingUpload,
        'savingsUpload' => $savingsUpload,
        'outflow' => $outflow,
        'inflow' => $inflow,
    ] = oppositeStagedTransfer();

    expect($outflow->transfer_pair_id)->toBeNull()
        ->and($inflow->transfer_pair_id)->toBeNull();

    $this->actingAs($user)
        ->patch(route('household.import.staged.update', $checkingUpload), [
            'rows' => [transferReviewRow($outflow, [
                'transfer_match_type' => 'staged',
                'transfer_match_id' => $inflow->id,
            ])],
        ])
        ->assertRedirect(route('household.import.review', $checkingUpload))
        ->assertSessionHasNoErrors();

    $outflow->refresh();
    $inflow->refresh();

    expect($outflow->transfer_pair_id)->not->toBeNull()
        ->and($outflow->transfer_pair_id)->toBe($inflow->transfer_pair_id)
        ->and(Transaction::query()->where('household_id', $household->id)->count())->toBe(0);

    $this->actingAs($user)->post(route('household.import.promote', $checkingUpload))->assertRedirect();
    $this->actingAs($user)->post(route('household.import.promote', $savingsUpload))->assertRedirect();

    $ledgerOut = Transaction::query()->find($outflow->refresh()->transaction_id);
    $ledgerIn = Transaction::query()->find($inflow->refresh()->transaction_id);

    expect($ledgerOut)->not->toBeNull()
        ->and($ledgerIn)->not->toBeNull()
        ->and($ledgerOut->transfer_pair_id)->toBe($ledgerIn->transfer_pair_id)
        ->and($ledgerOut->transfer_pair_id)->toBe($outflow->transfer_pair_id)
        ->and($ledgerOut->id)->not->toBe($ledgerIn->id);
});

test('rejecting a transfer suggestion leaves both ledger transactions unpaired', function () {
    [
        'user' => $user,
        'household' => $household,
        'checkingUpload' => $checkingUpload,
        'savingsUpload' => $savingsUpload,
        'outflow' => $outflow,
        'inflow' => $inflow,
    ] = oppositeStagedTransfer();

    $this->actingAs($user)
        ->patch(route('household.import.staged.update', $checkingUpload), [
            'rows' => [transferReviewRow($outflow, [
                'transfer_match_type' => 'staged',
                'transfer_match_id' => $inflow->id,
            ])],
        ])
        ->assertSessionHasNoErrors();

    expect($outflow->refresh()->transfer_pair_id)->not->toBeNull();

    $this->actingAs($user)
        ->patch(route('household.import.staged.update', $checkingUpload), [
            'rows' => [transferReviewRow($outflow, [
                'transfer_match_type' => null,
                'transfer_match_id' => null,
            ])],
        ])
        ->assertSessionHasNoErrors();

    expect($outflow->refresh()->transfer_pair_id)->toBeNull()
        ->and($inflow->refresh()->transfer_pair_id)->toBeNull();

    $this->actingAs($user)->post(route('household.import.promote', $checkingUpload))->assertRedirect();
    $this->actingAs($user)->post(route('household.import.promote', $savingsUpload))->assertRedirect();

    expect(Transaction::query()->where('household_id', $household->id)->count())->toBe(2)
        ->and(Transaction::query()->where('household_id', $household->id)->whereNotNull('transfer_pair_id')->count())->toBe(0);
});

test('confirming a staged row outside the three day window is rejected', function () {
    [
        'user' => $user,
        'checkingUpload' => $checkingUpload,
        'savingsUpload' => $savingsUpload,
        'outflow' => $outflow,
    ] = oppositeStagedTransfer();

    $outside = StagedTransaction::factory()->create([
        'statement_upload_id' => $savingsUpload->id,
        'date' => '2026-06-05',
        'payee' => 'Outside window',
        'amount' => 50000,
        'accept' => true,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->patch(route('household.import.staged.update', $checkingUpload), [
            'rows' => [transferReviewRow($outflow, [
                'transfer_match_type' => 'staged',
                'transfer_match_id' => $outside->id,
            ])],
        ])
        ->assertSessionHasErrors([
            'rows' => 'That staged row is no longer a valid transfer match.',
        ]);

    expect($outflow->refresh()->transfer_pair_id)->toBeNull()
        ->and($outside->refresh()->transfer_pair_id)->toBeNull();
});

test('another household cannot be confirmed as a transfer match', function () {
    [
        'user' => $user,
        'checkingUpload' => $checkingUpload,
        'outflow' => $outflow,
    ] = oppositeStagedTransfer();

    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['household_id' => $other->household()->id]);
    $otherUpload = StatementUpload::factory()->create([
        'household_id' => $other->household()->id,
        'account_id' => $otherAccount->id,
        'uploaded_by_user_id' => $other->id,
    ]);
    $otherRow = StagedTransaction::factory()->create([
        'statement_upload_id' => $otherUpload->id,
        'date' => '2026-06-02',
        'amount' => 50000,
        'accept' => true,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->patch(route('household.import.staged.update', $checkingUpload), [
            'rows' => [transferReviewRow($outflow, [
                'transfer_match_type' => 'staged',
                'transfer_match_id' => $otherRow->id,
            ])],
        ])
        ->assertSessionHasErrors([
            'rows' => 'That staged row is no longer a valid transfer match.',
        ]);

    expect($outflow->refresh()->transfer_pair_id)->toBeNull()
        ->and($otherRow->refresh()->transfer_pair_id)->toBeNull();
});

test('confirming a suggestion against an existing transaction pairs both ledger rows', function () {
    $user = User::factory()->create();
    $household = $user->household();
    $checking = Account::factory()->create(['household_id' => $household->id, 'name' => 'Chequing']);
    $savings = Account::factory()->create(['household_id' => $household->id, 'name' => 'Savings']);
    $upload = StatementUpload::factory()->create([
        'household_id' => $household->id,
        'account_id' => $checking->id,
        'uploaded_by_user_id' => $user->id,
        'source' => 'csv',
    ]);
    $staged = StagedTransaction::factory()->create([
        'statement_upload_id' => $upload->id,
        'date' => '2026-06-01',
        'payee' => 'Transfer to savings',
        'amount' => -50000,
        'accept' => true,
        'status' => 'pending',
    ]);
    $existing = Transaction::factory()->create([
        'household_id' => $household->id,
        'account_id' => $savings->id,
        'date' => '2026-06-04',
        'payee' => 'Transfer from chequing',
        'amount' => 50000,
        'category_id' => null,
        'transfer_pair_id' => null,
        'is_split' => false,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('household.import.review', $upload))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', function (Collection $rows) use ($staged, $existing): bool {
                $row = $rows->firstWhere('id', $staged->id);
                $match = collect($row['transfer_matches'])->first();

                return $row['transfer_pair_id'] === null
                    && $match['id'] === $existing->id
                    && $match['type'] === 'transaction'
                    && $match['days_apart'] === 3;
            })
        );

    expect($existing->transfer_pair_id)->toBeNull();

    $this->actingAs($user)
        ->patch(route('household.import.staged.update', $upload), [
            'rows' => [transferReviewRow($staged, [
                'transfer_match_type' => 'transaction',
                'transfer_match_id' => $existing->id,
            ])],
        ])
        ->assertSessionHasNoErrors();

    expect($staged->refresh()->transfer_pair_id)->not->toBeNull()
        ->and($existing->refresh()->transfer_pair_id)->toBeNull();

    $this->actingAs($user)->post(route('household.import.promote', $upload))->assertRedirect();

    $promoted = Transaction::query()->find($staged->refresh()->transaction_id);

    expect($promoted)->not->toBeNull()
        ->and($promoted->transfer_pair_id)->toBe($existing->refresh()->transfer_pair_id)
        ->and($promoted->transfer_pair_id)->not->toBeNull()
        ->and($promoted->id)->not->toBe($existing->id);
});
