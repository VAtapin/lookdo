<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantBookValuation;
use App\Models\TenantRequest;

class BookPurchasePricingService
{
    private const CONDITION_FACTORS = [
        'poor' => .75,
        'fair' => .90,
        'good' => 1.00,
        'very_good' => 1.10,
        'unknown' => .95,
    ];

    /**
     * @return array{amount: float, currency: string, anchored: bool, history_count: int}
     */
    public function stabilize(Tenant $tenant, string $isbn, float $suggestedPrice, string $currency, string $conditionGrade): array
    {
        $isbn = $this->canonicalIsbn($isbn);
        $currency = $this->currency($currency);
        $conditionGrade = $this->conditionGrade($conditionGrade);
        $suggestedPrice = max(.50, $suggestedPrice);
        $history = $isbn === '' ? collect() : TenantBookValuation::query()
            ->where('tenant_id', $tenant->id)
            ->where('isbn', $isbn)
            ->where('currency', $currency)
            ->where('created_at', '>=', now()->subMonths(12))
            ->latest('id')
            ->limit(12)
            ->get();

        if ($history->isEmpty()) {
            return [
                'amount' => $this->roundPrice($suggestedPrice),
                'currency' => $currency,
                'anchored' => false,
                'history_count' => 0,
            ];
        }

        $normalized = $history->map(function (TenantBookValuation $valuation): float {
            $factor = self::CONDITION_FACTORS[$this->conditionGrade($valuation->condition_grade)];

            return (float) $valuation->recommended_purchase_price / $factor;
        })->sort()->values();
        $middle = intdiv($normalized->count(), 2);
        $basePrice = (float) $normalized[$middle];
        $historicalMedian = $history->pluck('recommended_purchase_price')->map(fn ($price): float => (float) $price)->sort()->values();
        $historicalMiddle = intdiv($historicalMedian->count(), 2);
        $medianRecommendation = (float) $historicalMedian[$historicalMiddle];
        $conditionAdjusted = $basePrice * self::CONDITION_FACTORS[$conditionGrade];
        $bounded = min($medianRecommendation * 1.25, max($medianRecommendation * .75, $conditionAdjusted));

        return [
            'amount' => $this->roundPrice(max(.50, $bounded)),
            'currency' => $currency,
            'anchored' => true,
            'history_count' => $history->count(),
        ];
    }

    /** @param array<string, mixed> $context */
    public function remember(TenantRequest $request, string $isbn, string|float|int $price, string $conditionGrade = 'unknown', array $context = []): void
    {
        $isbn = $this->canonicalIsbn($isbn);
        [$amount, $currency] = $this->parsePrice($price);
        if ($isbn === '' || $amount <= 0) {
            return;
        }

        TenantBookValuation::query()->updateOrCreate(
            ['tenant_id' => $request->tenant_id, 'request_id' => $request->id],
            [
                'isbn' => $isbn,
                'recommended_purchase_price' => $this->roundPrice($amount),
                'currency' => $currency,
                'condition_grade' => $this->conditionGrade($conditionGrade),
                'source' => 'ai',
                'context' => $context,
            ],
        );
    }

    /** @return array{float, string} */
    public function parsePrice(string|float|int $price, string $fallbackCurrency = 'EUR'): array
    {
        if (is_int($price) || is_float($price)) {
            return [(float) $price, $this->currency($fallbackCurrency)];
        }

        $normalized = str_replace(',', '.', trim($price));
        preg_match('/\d+(?:\.\d{1,2})?/', $normalized, $amountMatch);
        preg_match('/\b[A-Z]{3}\b/i', $normalized, $currencyMatch);

        return [
            isset($amountMatch[0]) ? (float) $amountMatch[0] : 0.0,
            $this->currency($currencyMatch[0] ?? $fallbackCurrency),
        ];
    }

    public function format(float $amount, string $currency): string
    {
        return number_format($this->roundPrice($amount), 2, '.', '').' '.$this->currency($currency);
    }

    private function canonicalIsbn(string $value): string
    {
        $isbn = strtoupper((string) preg_replace('/[^0-9X]/i', '', $value));
        if (strlen($isbn) !== 10) {
            return strlen($isbn) === 13 ? $isbn : '';
        }

        $isbn13 = '978'.substr($isbn, 0, 9);
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $isbn13[$index] * ($index % 2 === 0 ? 1 : 3);
        }

        return $isbn13.((10 - ($sum % 10)) % 10);
    }

    private function conditionGrade(string $value): string
    {
        return array_key_exists($value, self::CONDITION_FACTORS) ? $value : 'unknown';
    }

    private function currency(string $value): string
    {
        $currency = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
    }

    private function roundPrice(float $amount): float
    {
        $step = $amount < 50 ? .50 : 1.00;

        return round($amount / $step) * $step;
    }
}
