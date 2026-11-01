<?php

/**
 * The pixel oracle's master is the saved Mobbin frame, not a screenshot of this page.
 *
 * Screen f5e0390b-fe1f-4c6f-aa6a-fecedd5b24cb is Monarch's web dashboard.
 * The file is that capture at 1920×1320, including the Mobbin attribution bar.
 * Widths 390, 768, and 1440 were not returned, so parity there stays untested.
 * The capture is not resized into those widths.
 */
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

it('leaves dashboard parity untested when Mobbin has no frame at that width', function (int $width) {
    $size = getimagesize(monarchDashboardFramePath());

    expect($size)->toBeArray();

    $frameWidth = $size[0];

    if ($frameWidth !== $width) {
        $this->markTestIncomplete(
            "No Mobbin frame exists for {$width}px. Saved dashboard frame f5e0390b is {$frameWidth}×{$size[1]}. Refusing to resize it. Parity at {$width} is untested.",
        );
    }

    $this->fail(
        "A {$width}px Mobbin frame is present, but this check must compare the rendered dashboard to that frame. It must not accept a screenshot of this page as the baseline.",
    );
})->with([
    '390 pixels' => [390],
    '768 pixels' => [768],
    '1440 pixels' => [1440],
]);
