<?php

use App\Models\Account;

it('imports a CSV statement into staging without touching the ledger', function () {
    $user = actingAsOwner();
    $account = Account::factory()->create([
        'household_id' => $user->household()->id,
        'name' => 'Daily Chequing',
    ]);

    visit('/household/import')
        ->select('@import-account', $account->id)
        ->attach('@import-file', base_path('tests/Fixtures/statements/sample-statement.csv'))
        ->assertSee('LOBLAWS #5025')
        ->assertSee('-$72.50')
        ->assertSee('$2,000.00')
        ->click('@import-submit')
        ->assertSee('Review import')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('staged_transactions', [
        'payee' => 'LOBLAWS #5025',
        'amount' => -7250,
    ]);
    $this->assertDatabaseHas('staged_transactions', [
        'payee' => 'PAYROLL DEPOSIT',
        'amount' => 200000,
    ]);

    // Staging never writes ledger rows.
    $this->assertDatabaseCount('transactions', 0);
});

it('reaches the import page from the transactions page', function () {
    actingAsOwner();

    visit('/household/transactions')
        ->click('Import')
        ->assertPathIs('/household/import')
        ->assertSee('Import statement');
});

function assertImportPreviewPixelBaseline(int $width, int $height): void
{
    test()->travelTo('2026-11-01 12:00:00');
    $user = actingAsOwner(['name' => 'Pixel Baseline']);
    $account = Account::factory()->create([
        'household_id' => $user->household()->id,
        'name' => 'Daily Chequing',
    ]);

    visit('/household/import')
        ->resize($width, $height)
        ->select('@import-account', $account->id)
        ->attach('@import-file', base_path('tests/Fixtures/statements/sample-statement.csv'))
        ->assertSee('LOBLAWS #5025')
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches();
}

it('pixel-diffs the CSV preview at 390 pixels', function () {
    assertImportPreviewPixelBaseline(390, 844);
});

it('pixel-diffs the CSV preview at 768 pixels', function () {
    assertImportPreviewPixelBaseline(768, 1024);
});

it('pixel-diffs the CSV preview at 1440 pixels', function () {
    assertImportPreviewPixelBaseline(1440, 1000);
});
