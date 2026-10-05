<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Reads real image headers straight from the file contents.
 *
 * A valid MIME guess only proves the extension maps to an image type, so this
 * second pass is what actually proves the bytes carry an image header, and it
 * rejects absurd dimensions before they are decoded anywhere. Header parsing
 * cannot prove the whole file decodes; the inference service performs that
 * full decode and rejects anything truncated.
 */
final class ImageInspector
{
    public function __construct(private readonly int $maxDimension) {}

    /**
     * @return array{width: int, height: int, mime: string}|null Null when the file is not a readable image.
     */
    public function inspect(UploadedFile $file): ?array
    {
        if (! $file->isValid()) {
            return null;
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false || ! isset($info[0], $info[1], $info['mime'])) {
            return null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width < 1 || $height < 1 || $width > $this->maxDimension || $height > $this->maxDimension) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime' => (string) $info['mime'],
        ];
    }

    public function isReadable(UploadedFile $file): bool
    {
        return $this->inspect($file) !== null;
    }
}
