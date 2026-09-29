<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecipeImageStorage
{
    private const SIZE = 500;

    private const DISK = 'recipe_images';

    public function store(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);

        if ($source === false) {
            throw ValidationException::withMessages([
                'image' => 'The uploaded file could not be processed as an image.',
            ]);
        }

        $source = $this->orientJpeg($source, $file);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $cropSize = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
        $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);
        $thumbnail = imagecreatetruecolor(self::SIZE, self::SIZE);

        if ($thumbnail === false) {
            imagedestroy($source);

            throw ValidationException::withMessages([
                'image' => 'The uploaded image could not be resized.',
            ]);
        }

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
        imagefill($thumbnail, 0, 0, $transparent);

        $resampled = imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            self::SIZE,
            self::SIZE,
            $cropSize,
            $cropSize,
        );

        imagedestroy($source);

        if (! $resampled) {
            imagedestroy($thumbnail);

            throw ValidationException::withMessages([
                'image' => 'The uploaded image could not be resized.',
            ]);
        }

        ob_start();
        $encoded = imagewebp($thumbnail, null, 82);
        $webp = ob_get_clean();
        imagedestroy($thumbnail);

        if (! $encoded || ! is_string($webp)) {
            throw ValidationException::withMessages([
                'image' => 'The uploaded image could not be encoded.',
            ]);
        }

        $path = 'recipes/'.Str::uuid().'.webp';

        if (! Storage::disk(self::DISK)->put($path, $webp)) {
            throw ValidationException::withMessages([
                'image' => 'The uploaded image could not be stored.',
            ]);
        }

        return $path;
    }

    public function deleteOwned(?string $path): void
    {
        if ($path === null || $path !== 'recipes/'.basename($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /** @param \GdImage|resource $source */
    private function orientJpeg(mixed $source, UploadedFile $file): mixed
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $source;
        }

        $exif = @exif_read_data($file->getRealPath());
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => null,
        };

        if ($angle === null) {
            return $source;
        }

        $rotated = imagerotate($source, $angle, 0);

        if ($rotated === false) {
            return $source;
        }

        imagedestroy($source);

        return $rotated;
    }
}
