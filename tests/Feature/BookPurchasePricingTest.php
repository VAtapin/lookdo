<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\BookPurchasePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookPurchasePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_submitted_price_anchors_a_repeated_isbn_for_the_same_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Book buyer',
            'slug' => 'book-price-memory',
            'country' => 'DE',
            'locale' => 'ru',
            'status' => 'active',
        ]);
        $request = $tenant->appRequests()->create([
            'number' => 'R-BOOK-PRICE-1',
            'status' => 'new',
            'summary' => 'Книга',
            'locale' => 'ru',
        ]);
        $pricing = app(BookPurchasePricingService::class);
        $pricing->remember($request, '978-3-16-148410-0', '8.00 EUR', 'fair');
        $conflictingRequest = $tenant->appRequests()->create([
            'number' => 'R-BOOK-PRICE-CONFLICT',
            'status' => 'new',
            'summary' => 'Та же книга',
            'locale' => 'ru',
        ]);
        $pricing->remember($conflictingRequest, '9783161484100', '2.50 EUR', 'fair');

        $repeat = $pricing->stabilize($tenant, '3-16-148410-X', 2.50, 'EUR', 'fair');
        $betterCondition = $pricing->stabilize($tenant, '9783161484100', 2.50, 'EUR', 'good');

        $this->assertTrue($repeat['anchored']);
        $this->assertSame(8.0, $repeat['amount']);
        $this->assertSame(9.0, $betterCondition['amount']);
        $this->assertSame(2, $repeat['history_count']);
    }

    public function test_price_memory_is_isolated_between_tenants(): void
    {
        $firstTenant = Tenant::create([
            'name' => 'First buyer',
            'slug' => 'first-book-buyer',
            'country' => 'DE',
            'locale' => 'ru',
            'status' => 'active',
        ]);
        $secondTenant = Tenant::create([
            'name' => 'Second buyer',
            'slug' => 'second-book-buyer',
            'country' => 'DE',
            'locale' => 'ru',
            'status' => 'active',
        ]);
        $request = $firstTenant->appRequests()->create([
            'number' => 'R-BOOK-PRICE-2',
            'status' => 'new',
            'summary' => 'Книга',
            'locale' => 'ru',
        ]);
        $pricing = app(BookPurchasePricingService::class);
        $pricing->remember($request, '9783161484100', '8.00 EUR', 'fair');

        $result = $pricing->stabilize($secondTenant, '9783161484100', 2.50, 'EUR', 'fair');

        $this->assertFalse($result['anchored']);
        $this->assertSame(2.5, $result['amount']);
    }
}
