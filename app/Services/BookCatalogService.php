<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class BookCatalogService
{
    /** @return array<string, mixed> */
    public function lookup(string $rawIsbn): array
    {
        $isbn = $this->normalize($rawIsbn);
        if (! $this->isValid($isbn)) {
            return [];
        }

        $googleQuery = ['q' => 'isbn:'.$isbn, 'maxResults' => 1, 'projection' => 'full'];
        if (filled(config('services.books.google_api_key'))) {
            $googleQuery['key'] = (string) config('services.books.google_api_key');
        }

        try {
            $responses = Http::pool(fn (Pool $pool): array => [
                $pool->as('open_library')->acceptJson()->connectTimeout(2)->timeout(6)->get('https://openlibrary.org/search.json', [
                    'q' => 'isbn:'.$isbn,
                    'limit' => 1,
                    'fields' => 'key,title,author_name,publisher,publish_date,first_publish_year,number_of_pages_median,language,isbn,edition_name,cover_i',
                ]),
                $pool->as('google_books')->acceptJson()->connectTimeout(2)->timeout(6)->get('https://www.googleapis.com/books/v1/volumes', $googleQuery),
            ]);
        } catch (\Throwable) {
            return [];
        }

        $openLibrary = $responses['open_library'] ?? null;
        $googleBooks = $responses['google_books'] ?? null;
        $open = $openLibrary?->successful() ? (array) $openLibrary->json('docs.0', []) : [];
        $google = $googleBooks?->successful() ? (array) $googleBooks->json('items.0', []) : [];
        $volume = (array) ($google['volumeInfo'] ?? []);
        $sale = (array) ($google['saleInfo'] ?? []);
        $dimensions = (array) ($volume['dimensions'] ?? []);
        $price = (array) ($sale['retailPrice'] ?? $sale['listPrice'] ?? []);

        if ($open === [] && $google === []) {
            return [];
        }

        $authors = (array) ($volume['authors'] ?? $open['author_name'] ?? []);
        $publishers = (array) ($open['publisher'] ?? []);
        $publishDates = (array) ($open['publish_date'] ?? []);
        $editions = (array) ($open['edition_name'] ?? []);
        $languages = (array) ($open['language'] ?? []);
        $publishedDate = (string) ($volume['publishedDate'] ?? $publishDates[0] ?? $open['first_publish_year'] ?? '');

        return array_filter([
            'isbn' => $isbn,
            'title' => (string) ($volume['title'] ?? $open['title'] ?? ''),
            'author' => implode(', ', array_filter(array_map('strval', $authors))),
            'publisher' => (string) ($volume['publisher'] ?? $publishers[0] ?? ''),
            'publication_year' => $publishedDate !== '' ? substr($publishedDate, 0, 4) : '',
            'edition' => (string) ($volume['subtitle'] ?? $editions[0] ?? ''),
            'pages' => (string) ($volume['pageCount'] ?? $open['number_of_pages_median'] ?? ''),
            'dimensions' => implode(' × ', array_filter([
                $dimensions['height'] ?? null,
                $dimensions['width'] ?? null,
                $dimensions['thickness'] ?? null,
            ])),
            'language' => (string) ($volume['language'] ?? $languages[0] ?? ''),
            'listing_description' => trim(strip_tags((string) ($volume['description'] ?? ''))),
            'catalog_url' => filled($open['key'] ?? null)
                ? 'https://openlibrary.org'.(string) $open['key']
                : (string) ($volume['infoLink'] ?? ''),
            'cover_url' => filled($open['cover_i'] ?? null)
                ? 'https://covers.openlibrary.org/b/id/'.(int) $open['cover_i'].'-L.jpg'
                : (string) data_get($volume, 'imageLinks.thumbnail', ''),
            'reference_price' => isset($price['amount']) ? (float) $price['amount'] : null,
            'reference_currency' => (string) ($price['currencyCode'] ?? ''),
            'catalog_sources' => array_values(array_filter([
                $open !== [] ? 'Open Library' : null,
                $google !== [] ? 'Google Books' : null,
            ])),
        ], fn (mixed $value): bool => $value !== '' && $value !== null && $value !== []);
    }

    public function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^0-9X]/i', '', $value));
    }

    public function isValid(string $isbn): bool
    {
        if (strlen($isbn) === 10) {
            $sum = 0;
            for ($index = 0; $index < 10; $index++) {
                $digit = $isbn[$index] === 'X' && $index === 9 ? 10 : (ctype_digit($isbn[$index]) ? (int) $isbn[$index] : -1);
                if ($digit < 0) {
                    return false;
                }
                $sum += $digit * (10 - $index);
            }

            return $sum % 11 === 0;
        }

        if (strlen($isbn) === 13 && ctype_digit($isbn)) {
            $sum = 0;
            for ($index = 0; $index < 13; $index++) {
                $sum += (int) $isbn[$index] * ($index % 2 === 0 ? 1 : 3);
            }

            return $sum % 10 === 0;
        }

        return false;
    }
}
