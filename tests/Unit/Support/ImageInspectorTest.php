<?php

use App\Support\ImageInspector;
use Illuminate\Http\UploadedFile;

function inspector(int $maxDimension = 4096): ImageInspector
{
    return new ImageInspector($maxDimension);
}

it('describes a valid png', function () {
    $inspection = inspector()->inspect(UploadedFile::fake()->image('scan.png', 320, 240));

    expect($inspection)->not->toBeNull()
        ->and($inspection['width'])->toBe(320)
        ->and($inspection['height'])->toBe(240)
        ->and($inspection['mime'])->toBe('image/png');
});

it('describes a valid jpeg', function () {
    $inspection = inspector()->inspect(UploadedFile::fake()->image('scan.jpg', 150, 150));

    expect($inspection)->not->toBeNull()
        ->and($inspection['mime'])->toBe('image/jpeg');
});

it('reports a non image file as unreadable even when named as an image', function () {
    expect(inspector()->isReadable(
        UploadedFile::fake()->createWithContent('scan.png', '<?php echo "not an image";')
    ))->toBeFalse();
});

it('reports an empty file as unreadable', function () {
    expect(inspector()->isReadable(
        UploadedFile::fake()->createWithContent('empty.png', '')
    ))->toBeFalse();
});

it('rejects an image whose dimensions exceed the configured limit', function () {
    expect(inspector(100)->isReadable(
        UploadedFile::fake()->image('scan.png', 320, 240)
    ))->toBeFalse();
});

it('accepts an image sitting exactly on the configured dimension limit', function () {
    expect(inspector(320)->isReadable(
        UploadedFile::fake()->image('scan.png', 320, 240)
    ))->toBeTrue();
});
