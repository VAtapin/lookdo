<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_book_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_id')->constrained('tenant_requests')->cascadeOnDelete();
            $table->string('isbn', 13);
            $table->decimal('recommended_purchase_price', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('condition_grade', 20)->default('unknown');
            $table->string('source', 30)->default('ai');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'request_id']);
            $table->index(['tenant_id', 'isbn', 'created_at']);
        });

        $this->backfillSubmittedBookPrices();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_book_valuations');
    }

    private function backfillSubmittedBookPrices(): void
    {
        DB::table('tenant_requests')
            ->select(['id', 'tenant_id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(200, function ($requests): void {
                $requestIds = $requests->pluck('id');
                $values = DB::table('tenant_request_values')
                    ->whereIn('request_id', $requestIds)
                    ->whereIn('field_key', ['isbn', 'ai_condition_assessment'])
                    ->get()
                    ->groupBy('request_id');

                foreach ($requests as $request) {
                    $requestValues = $values->get($request->id, collect())->keyBy('field_key');
                    $isbnPayload = $this->decodeValue($requestValues->get('isbn')?->value);
                    $assessment = $this->decodeValue($requestValues->get('ai_condition_assessment')?->value);
                    $isbn = $this->canonicalIsbn((string) ($isbnPayload['value'] ?? ''));
                    [$amount, $currency] = $this->parsePrice((string) ($assessment['recommended_purchase_price'] ?? ''));
                    if ($isbn === '' || $amount <= 0) {
                        continue;
                    }

                    DB::table('tenant_book_valuations')->insertOrIgnore([
                        'tenant_id' => $request->tenant_id,
                        'request_id' => $request->id,
                        'isbn' => $isbn,
                        'recommended_purchase_price' => $amount,
                        'currency' => $currency,
                        'condition_grade' => (string) ($assessment['condition_grade'] ?? 'unknown'),
                        'source' => 'migration',
                        'context' => json_encode(['backfilled' => true]),
                        'created_at' => $request->created_at,
                        'updated_at' => $request->updated_at,
                    ]);
                }
            });
    }

    /** @return array<string, mixed> */
    private function decodeValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{float, string} */
    private function parsePrice(string $value): array
    {
        $value = str_replace(',', '.', trim($value));
        preg_match('/\d+(?:\.\d{1,2})?/', $value, $amount);
        preg_match('/\b[A-Z]{3}\b/i', $value, $currency);

        return [
            isset($amount[0]) ? (float) $amount[0] : 0.0,
            strtoupper($currency[0] ?? 'EUR'),
        ];
    }

    private function canonicalIsbn(string $value): string
    {
        $isbn = strtoupper((string) preg_replace('/[^0-9X]/i', '', $value));
        if (strlen($isbn) === 13) {
            return $isbn;
        }
        if (strlen($isbn) !== 10) {
            return '';
        }

        $isbn13 = '978'.substr($isbn, 0, 9);
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $isbn13[$index] * ($index % 2 === 0 ? 1 : 3);
        }

        return $isbn13.((10 - ($sum % 10)) % 10);
    }
};
