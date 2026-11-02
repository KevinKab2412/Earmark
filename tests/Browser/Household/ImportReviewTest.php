<?php

use App\Models\Account;
use App\Models\StagedTransaction;
use App\Models\StatementUpload;
use Inertia\Testing\AssertableInertia as Assert;

it('promotes staged rows into the ledger from the review page', function () {
    $user = actingAsOwner();
    $household = $user->household();
    $account = Account::factory()->create(['household_id' => $household->id]);
    $upload = StatementUpload::factory()->create([
        'household_id' => $household->id,
        'account_id' => $account->id,
        'uploaded_by_user_id' => $user->id,
        'source' => 'csv',
    ]);
    StagedTransaction::factory()->create([
        'statement_upload_id' => $upload->id,
        'payee' => 'LOBLAWS #5025',
        'amount' => -7250,
        'accept' => true,
        'status' => 'pending',
    ]);

    visit('/household/import/'.$upload->id.'/review')
        ->assertSee('Review import')
        ->click('@review-promote')
        ->assertSee('Promoted')
        ->assertSee('LOBLAWS #5025')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('transactions', [
        'import_batch_id' => $upload->id,
        'amount' => -7250,
        'source' => 'imported_csv',
    ]);
});

it('pairs a suggested transfer only after save and unpairs both rows when removed', function () {
    $user = actingAsOwner();
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
        'date' => '2026-10-31',
        'payee' => 'Transfer to savings',
        'amount' => -50000,
        'accept' => true,
        'status' => 'pending',
    ]);
    $inflow = StagedTransaction::factory()->create([
        'statement_upload_id' => $savingsUpload->id,
        'date' => '2026-11-01',
        'payee' => 'Transfer from chequing',
        'amount' => 50000,
        'accept' => true,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->get(route('household.import.review', $checkingUpload))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.transfer_matches.0.payee', 'Transfer from chequing')
        );

    $page = visit('/household/import/'.$checkingUpload->id.'/review')
        ->assertScript('JSON.parse(document.querySelector("script[data-page]").textContent).props.rows[0].transfer_matches[0]?.payee === "Transfer from chequing"')
        ->assertNoJavaScriptErrors()
        ->click('@confirm-transfer-match')
        ->assertCount('[data-test="confirmed-transfer-match"]', 1);

    expect($outflow->fresh()->transfer_pair_id)->toBeNull()
        ->and($inflow->fresh()->transfer_pair_id)->toBeNull();

    $page->click('@review-save')
        ->assertCount('[data-test="confirmed-transfer-match"]', 1);

    $pairId = $outflow->fresh()->transfer_pair_id;
    expect($pairId)->not->toBeNull()
        ->and($inflow->fresh()->transfer_pair_id)->toBe($pairId);

    $page->click('@reject-transfer-match')
        ->click('@review-save')
        ->assertCount('[data-test="transfer-match-suggestions"]', 1)
        ->assertNoJavaScriptErrors();

    expect($outflow->fresh()->transfer_pair_id)->toBeNull()
        ->and($inflow->fresh()->transfer_pair_id)->toBeNull();
});
