<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeException;
use App\Services\Billing\PricingCalculator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PixelforgeClient
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $rawKey = $apiKey ?: (config('services.pixelforge.api_key') ?: getenv('PIXELFORGE_API_KEY') ?: ($_ENV['PIXELFORGE_API_KEY'] ?? ''));
        $this->apiKey = trim((string) $rawKey, " \t\n\r\0\x0B\"'");

        $rawUrl = $baseUrl ?: (config('services.pixelforge.base_url') ?: getenv('PIXELFORGE_BASE_URL') ?: 'https://pixelforge.promptedit.dev/v1');
        $this->baseUrl = rtrim(trim((string) $rawUrl, " \t\n\r\0\x0B\"'"), '/');
    }

    /**
     * Submit a new image generation request with an Idempotency-Key.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        $url = "{$this->baseUrl}/generations";
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $response = Http::withToken($this->apiKey)
                    ->withHeaders([
                        'Idempotency-Key' => $idempotencyKey,
                        'Accept' => 'application/json',
                    ])
                    ->timeout(15)
                    ->post($url, $params);

                if ($response->status() === 202) {
                    return $response->json();
                }

                // Handle 503 Overloaded: Pixelforge docs recommend retrying with Idempotency-Key
                if ($response->status() === 503 && $attempts < $maxAttempts) {
                    $retryAfter = (int) ($response->header('Retry-After') ?: 2);
                    Log::warning("Pixelforge overloaded (503). Retrying after {$retryAfter}s (attempt {$attempts}/{$maxAttempts})");
                    sleep(min($retryAfter, 5));
                    continue;
                }

                $this->handleErrorResponse($response);
            } catch (ConnectionException $e) {
                if ($attempts >= $maxAttempts) {
                    throw new PixelforgeException(
                        "Unable to connect to Pixelforge: {$e->getMessage()}",
                        'connection_error',
                        500
                    );
                }
                sleep(2);
            }
        }

        throw new PixelforgeException('Pixelforge request timed out after retries.', 'request_timeout', 504);
    }

    /**
     * Retrieve a generation by ID.
     *
     * @throws PixelforgeException
     */
    public function getGeneration(string $id): array
    {
        $response = Http::withToken($this->apiKey)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get("{$this->baseUrl}/generations/{$id}");

        if ($response->successful()) {
            return $response->json();
        }

        $this->handleErrorResponse($response);
    }

    /**
     * Get live pricing and token parameters (cached for 1 hour).
     * If unauthenticated or unreachable, falls back to published default rates.
     */
    public function getPricing(): array
    {
        return Cache::remember('pixelforge_pricing_rates', 3600, function () {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['Accept' => 'application/json'])
                    ->get("{$this->baseUrl}/pricing");

                if ($response->successful()) {
                    return $response->json();
                }
            } catch (\Throwable $e) {
                Log::notice("Could not fetch live pricing from Pixelforge, using default rates: {$e->getMessage()}");
            }

            return [
                'rates' => PricingCalculator::DEFAULT_RATES,
                'tokens' => [
                    'characters_per_text_token' => PricingCalculator::CHARS_PER_TEXT_TOKEN,
                    'per_reference_image' => PricingCalculator::TOKENS_PER_IMAGE,
                ],
            ];
        });
    }

    /**
     * Query provider usage and costs billed for this API key.
     * Pixelforge bills on the UTC day the generation completed.
     *
     * @throws PixelforgeException
     */
    public function getUsage(string $startDate, string $endDate): array
    {
        $response = Http::withToken($this->apiKey)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(15)
            ->get("{$this->baseUrl}/usage", [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        $this->handleErrorResponse($response);
    }

    /**
     * Standardized error response parser based on Pixelforge API error format.
     *
     * @throws PixelforgeException
     */
    protected function handleErrorResponse(Response $response): never
    {
        $status = $response->status();
        $body = $response->json();
        $error = $body['error'] ?? [];

        $code = $error['code'] ?? 'unknown_error';
        $type = $error['type'] ?? 'api_error';
        $message = $error['message'] ?? $response->body();
        $param = $error['param'] ?? null;
        $retryAfter = $response->header('Retry-After') ? (int) $response->header('Retry-After') : null;
        $details = $error['errors'] ?? [];

        throw new PixelforgeException(
            message: $message,
            errorCode: $code,
            httpStatus: $status,
            errorType: $type,
            param: $param,
            retryAfter: $retryAfter,
            details: $details
        );
    }
}
