<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageStorageService
{
    /**
     * Store a browser upload in an efficient WebP representation whenever GD supports it.
     */
    public function storeUploaded(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        int $maxWidth = 2048,
        int $maxHeight = 2048,
        int $quality = 82,
    ): string {
        $mime = (string) $file->getMimeType();
        if (! $this->canOptimize($mime)) {
            $path = $file->store($directory, $disk);
            if (! is_string($path)) {
                throw new RuntimeException('The uploaded file could not be stored.');
            }

            return $path;
        }

        $contents = file_get_contents($file->getPathname());
        if (! is_string($contents)) {
            throw new RuntimeException('The uploaded image could not be read.');
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            $path = $file->store($directory, $disk);
            if (! is_string($path)) {
                throw new RuntimeException('The uploaded file could not be stored.');
            }

            return $path;
        }

        $image = $this->orientJpeg($image, $file, $mime);

        return $this->storeOptimizedImage($image, $directory, $disk, $maxWidth, $maxHeight, $quality);
    }

    /**
     * Store generated image bytes using the same size and compression policy as uploads.
     */
    public function storeBytes(
        string $contents,
        string $directory,
        string $fallbackExtension = 'png',
        string $disk = 'public',
        int $maxWidth = 2048,
        int $maxHeight = 2048,
        int $quality = 82,
    ): string {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            $path = trim($directory, '/').'/'.Str::uuid().'.'.preg_replace('/[^a-z0-9]+/i', '', $fallbackExtension);
            if (! Storage::disk($disk)->put($path, $contents)) {
                throw new RuntimeException('The generated image could not be stored.');
            }

            return $path;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            $path = trim($directory, '/').'/'.Str::uuid().'.'.preg_replace('/[^a-z0-9]+/i', '', $fallbackExtension);
            if (! Storage::disk($disk)->put($path, $contents)) {
                throw new RuntimeException('The generated image could not be stored.');
            }

            return $path;
        }

        return $this->storeOptimizedImage($image, $directory, $disk, $maxWidth, $maxHeight, $quality);
    }

    private function canOptimize(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && function_exists('imagecreatefromstring')
            && function_exists('imagewebp');
    }

    private function orientJpeg(mixed $image, UploadedFile $file, string $mime): mixed
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file->getPathname())['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }

    private function storeOptimizedImage(
        mixed $source,
        string $directory,
        string $disk,
        int $maxWidth,
        int $maxHeight,
        int $quality,
    ): string {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxWidth / max(1, $width), $maxHeight / max(1, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            imagedestroy($source);
            throw new RuntimeException('The optimized image canvas could not be created.');
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $encoded = imagewebp($target, null, max(60, min(92, $quality)));
        $contents = ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);
        if (! $encoded || ! is_string($contents)) {
            throw new RuntimeException('The optimized image could not be encoded.');
        }

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';
        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('The optimized image could not be stored.');
        }

        return $path;
    }
}
