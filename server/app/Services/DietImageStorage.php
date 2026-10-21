<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DietImageStorage
{
    private const SIZE = 500;

    // The existing disk serves images from the public web root on hosting.
    private const DISK = 'recipe_images';

    public function store(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        if ($source === false) {
            throw ValidationException::withMessages(['thumbnail' => 'Imaginea nu a putut fi procesată.']);
        }

        $source = $this->orientJpeg($source, $file);
        $width = imagesx($source);
        $height = imagesy($source);
        $crop = min($width, $height);
        $thumbnail = imagecreatetruecolor(self::SIZE, self::SIZE);
        if ($thumbnail === false) {
            imagedestroy($source);
            throw ValidationException::withMessages(['thumbnail' => 'Imaginea nu a putut fi redimensionată.']);
        }
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagefill($thumbnail, 0, 0, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
        $resampled = imagecopyresampled(
            $thumbnail, $source, 0, 0, (int) floor(($width - $crop) / 2),
            (int) floor(($height - $crop) / 2), self::SIZE, self::SIZE, $crop, $crop,
        );
        imagedestroy($source);
        if (! $resampled) {
            imagedestroy($thumbnail);
            throw ValidationException::withMessages(['thumbnail' => 'Imaginea nu a putut fi redimensionată.']);
        }
        ob_start();
        $encoded = imagewebp($thumbnail, null, 82);
        $webp = ob_get_clean();
        imagedestroy($thumbnail);
        if (! $encoded || ! is_string($webp)) {
            throw ValidationException::withMessages(['thumbnail' => 'Imaginea nu a putut fi codificată.']);
        }
        $path = 'diets/'.Str::uuid().'.webp';
        if (! Storage::disk(self::DISK)->put($path, $webp)) {
            throw ValidationException::withMessages(['thumbnail' => 'Imaginea nu a putut fi salvată.']);
        }

        return $path;
    }

    public function deleteOwned(?string $path): void
    {
        if ($path !== null && $path === 'diets/'.basename($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return Storage::disk(self::DISK)->url($path);
    }

    private function orientJpeg(mixed $source, UploadedFile $file): mixed
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $source;
        }
        $exif = @exif_read_data($file->getRealPath());
        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? null) : null) {
            3 => 180, 6 => -90, 8 => 90, default => null,
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
