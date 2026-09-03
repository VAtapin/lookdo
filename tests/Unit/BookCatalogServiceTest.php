<?php

namespace Tests\Unit;

use App\Services\BookCatalogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookCatalogServiceTest extends TestCase
{
    public function test_catalog_lookup_tolerates_connection_failures(): void
    {
        Http::fake(fn () => throw new ConnectionException('Catalog unavailable'));

        $this->assertSame([], (new BookCatalogService)->lookup('9783161484100'));
    }

    public function test_catalog_lookup_uses_an_available_provider_when_the_other_one_fails(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'openlibrary.org')) {
                throw new ConnectionException('Open Library unavailable');
            }

            return Http::response([
                'items' => [[
                    'volumeInfo' => [
                        'title' => 'The Test Book',
                        'authors' => ['A. Writer'],
                    ],
                ]],
            ]);
        });

        $result = (new BookCatalogService)->lookup('9783161484100');

        $this->assertSame('The Test Book', $result['title']);
        $this->assertSame('A. Writer', $result['author']);
        $this->assertSame(['Google Books'], $result['catalog_sources']);
    }
}
