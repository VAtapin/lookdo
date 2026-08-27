<?php

namespace Tests\Unit;

use App\Services\ImageStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageStorageServiceTest extends TestCase
{
    public function test_uploaded_photos_are_resized_and_stored_as_webp(): void
    {
        Storage::fake('public');
        $upload = UploadedFile::fake()->image('camera.jpg', 1200, 900);

        $path = app(ImageStorageService::class)->storeUploaded($upload, 'optimized', 'public', 320, 240, 80);

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame('image/webp', Storage::disk('public')->mimeType($path));
        [$width, $height] = getimagesize(Storage::disk('public')->path($path));
        $this->assertLessThanOrEqual(320, $width);
        $this->assertLessThanOrEqual(240, $height);
    }
}
