<?php

it('opens the import page from the sidebar', function () {
    actingAsOwner();

    visit('/household/dashboard')
        ->assertSee('Dashboard')
        ->assertSee('Budget')
        ->assertSee('Reports')
        ->assertSee('Goals')
        ->assertSee('Forecasting')
        ->click('Import')
        ->assertPathIs('/household/import')
        ->assertSee('Import statement')
        ->assertNoJavaScriptErrors();
});

it('opens the budget from the sidebar', function () {
    actingAsOwner();

    visit('/household/dashboard')
        ->click('Budget')
        ->assertPathIs('/household/plan')
        ->assertNoJavaScriptErrors();
});
