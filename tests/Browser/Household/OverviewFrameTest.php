<?php

function monarchDashboardFramePath(): string
{
    return dirname(__DIR__, 2).'/Fixtures/mobbin/f5e0390b-fe1f-4c6f-aa6a-fecedd5b24cb.jpg';
}

it('keeps the Mobbin dashboard capture as the pixel baseline', function () {
    $bytes = file_get_contents(monarchDashboardFramePath());

    expect($bytes)->toBeString();
    expect(hash('sha256', $bytes))->toBe('337c1f434eb3a7c201554c667cfd259271d7d15ee9ae95caf81a177f906e785b');

    $size = getimagesizefromstring($bytes);

    expect($size)->toBeArray();
    expect($size[0])->toBe(1920);
    expect($size[1])->toBe(1320);
});

/**
 * Pest stores pixel-diff snapshots for our rendered dashboard. The Mobbin file
 * above remains the external reference; it is never resized to invent a frame.
 */
function assertOverviewPixelBaseline(int $width, int $height): void
{
    test()->travelTo('2026-11-01 12:00:00');
    actingAsOwner(['name' => 'Pixel Baseline']);

    visit('/household/dashboard')
        ->resize($width, $height)
        ->assertNoJavaScriptErrors()
        ->assertScreenshotMatches();
}

it('pixel-diffs the mobile overview at 390 pixels', function () {
    assertOverviewPixelBaseline(390, 844);
});

it('pixel-diffs the tablet overview at 768 pixels', function () {
    assertOverviewPixelBaseline(768, 1024);
});

it('pixel-diffs the desktop overview at 1440 pixels', function () {
    assertOverviewPixelBaseline(1440, 1000);
});
