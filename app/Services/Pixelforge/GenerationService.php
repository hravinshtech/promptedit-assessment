<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\CreditLedgerService;
use App\Services\Billing\PricingCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GenerationService
{
    public function __construct(
        protected PixelforgeClient $client,
        protected PricingCalculator $pricingCalculator,
        protected CreditLedgerService $creditLedgerService
    ) {}

    /**
     * Create and submit a new image generation.
     *
     * @throws PixelforgeException|InvalidArgumentException
     */
    public function create(User $user, array $data): Generation
    {
        $prompt = trim($data['prompt']);
        $size = Size::from($data['size'] ?? Size::Square->value);
        $quality = Quality::from($data['quality'] ?? Quality::Medium->value);
        $referenceImages = array_values(array_filter($data['reference_images'] ?? []));

        // Fetch live rates or default rates
        $pricingData = $this->client->getPricing();
        $rates = $pricingData['rates'] ?? PricingCalculator::DEFAULT_RATES;

        // Up-front estimation
        $estimate = $this->pricingCalculator->estimate($prompt, $size, $quality, $referenceImages, $rates);
        $estimatedUserCost = $estimate['user_cost']['estimated'];
        $estimatedProviderCost = $estimate['provider_cost']['estimated'];

        // Balance check
        if (! $user->canAfford($estimatedUserCost)) {
            throw new InvalidArgumentException(sprintf(
                'Insufficient credit balance. Generation estimated at $%s, your balance is $%s.',
                number_format((float) $estimatedUserCost, 4),
                number_format((float) $user->credit_balance, 4)
            ));
        }

        $internalId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();

        // 1. Persist local generation record and hold funds
        /** @var Generation $generation */
        $generation = DB::transaction(function () use (
            $user,
            $internalId,
            $idempotencyKey,
            $prompt,
            $size,
            $quality,
            $referenceImages,
            $rates,
            $estimatedProviderCost,
            $estimatedUserCost
        ) {
            $record = Generation::create([
                'id' => $internalId,
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'status' => GenerationStatus::Queued,
                'rates' => $rates,
                'estimated_provider_cost' => $estimatedProviderCost,
                'estimated_user_cost' => $estimatedUserCost,
                'actual_provider_cost' => '0.00000000',
                'actual_user_cost' => '0.00000000',
                'submitted_at' => now(),
            ]);

            $this->creditLedgerService->holdForGeneration($user, $record, $estimatedUserCost);

            return $record;
        });

        // 2. Dispatch to Pixelforge API
        try {
            $payload = [
                'prompt' => $prompt,
                'size' => $size->value,
                'quality' => $quality->value,
                'reference_images' => $referenceImages,
                'metadata' => [
                    'local_id' => $internalId,
                    'user_id' => (string) $user->id,
                ],
            ];

            $response = $this->client->createGeneration($payload, $idempotencyKey);

            $generation->update([
                'pixelforge_id' => $response['id'] ?? null,
                'rates' => $response['rates'] ?? $rates,
            ]);

            return $generation->fresh();
        } catch (PixelforgeException $e) {
            // If submission fails immediately (e.g. 400 content_policy_violation, 429 quota, etc.)
            DB::transaction(function () use ($generation, $e) {
                $generation->update([
                    'status' => GenerationStatus::Failed,
                    'error_code' => $e->getErrorCode(),
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                    'actual_provider_cost' => '0.00000000',
                    'actual_user_cost' => '0.00000000',
                ]);

                // Refund the held balance immediately
                $this->creditLedgerService->refundGeneration($generation, $e->getMessage());
            });

            throw $e;
        }
    }

    /**
     * Process an incoming webhook event from Pixelforge idempotently.
     */
    public function handleWebhook(array $eventPayload, int $deliveryAttempt = 1): void
    {
        $eventId = $eventPayload['id'] ?? null;
        $eventType = $eventPayload['type'] ?? '';

        if (! $eventId || ! $eventType) {
            return;
        }

        // Ping events simply acknowledge connectivity
        if ($eventType === 'ping') {
            Log::info("Received Pixelforge ping webhook {$eventId}");
            return;
        }

        $genData = $eventPayload['data']['object'] ?? null;
        if (! is_array($genData)) {
            return;
        }

        DB::transaction(function () use ($eventId, $eventType, $deliveryAttempt, $eventPayload, $genData) {
            // Deduplication: record event ID atomically
            $existingEvent = WebhookEvent::where('id', $eventId)->lockForUpdate()->first();
            if ($existingEvent) {
                Log::info("Ignoring duplicate Pixelforge webhook event {$eventId} (attempt {$deliveryAttempt})");
                return;
            }

            WebhookEvent::create([
                'id' => $eventId,
                'event_type' => $eventType,
                'delivery_attempt' => $deliveryAttempt,
                'payload' => $eventPayload,
                'processed_at' => now(),
            ]);

            $pixelforgeId = $genData['id'] ?? null;
            $localId = $genData['metadata']['local_id'] ?? null;

            /** @var Generation|null $generation */
            $generation = Generation::where(function ($query) use ($pixelforgeId, $localId) {
                if ($pixelforgeId) {
                    $query->where('pixelforge_id', $pixelforgeId);
                }
                if ($localId) {
                    $query->orWhere('id', $localId);
                }
            })->lockForUpdate()->first();

            if (! $generation) {
                Log::warning("Received webhook {$eventId} for unknown generation pixelforge_id={$pixelforgeId}, local_id={$localId}");
                return;
            }

            if (! $generation->pixelforge_id && $pixelforgeId) {
                $generation->pixelforge_id = $pixelforgeId;
            }

            $this->applyGenerationUpdate($generation, $genData);
        });
    }

    /**
     * Sync a generation by querying GET /v1/generations/{id} (polling fallback).
     */
    public function syncGeneration(Generation $generation): Generation
    {
        if ($generation->status->isFinal() || ! $generation->pixelforge_id) {
            return $generation;
        }

        try {
            $genData = $this->client->getGeneration($generation->pixelforge_id);

            DB::transaction(function () use ($generation, $genData) {
                /** @var Generation $lockedGen */
                $lockedGen = Generation::where('id', $generation->id)->lockForUpdate()->firstOrFail();
                $this->applyGenerationUpdate($lockedGen, $genData);
            });

            return $generation->fresh();
        } catch (\Throwable $e) {
            Log::warning("Failed to sync generation {$generation->id}: {$e->getMessage()}");
            return $generation;
        }
    }

    /**
     * Apply state transition and billing changes according to the state machine.
     */
    protected function applyGenerationUpdate(Generation $generation, array $genData): void
    {
        $incomingStatus = GenerationStatus::tryFrom($genData['status'] ?? '');
        if (! $incomingStatus) {
            return;
        }

        // Out-of-order guard: never transition backwards or from terminal states
        if (! $generation->status->canTransitionTo($incomingStatus)) {
            Log::info("Ignoring out-of-order transition from {$generation->status->value} to {$incomingStatus->value} for generation {$generation->id}");
            return;
        }

        if ($incomingStatus === GenerationStatus::Processing) {
            $generation->status = GenerationStatus::Processing;
            $generation->started_at = ! empty($genData['started_at'])
                ? Carbon::parse($genData['started_at'])
                : now();
            $generation->save();
            return;
        }

        if ($incomingStatus === GenerationStatus::Succeeded) {
            $generation->status = GenerationStatus::Succeeded;
            $generation->image_url = $genData['image_url'] ?? null;
            $generation->image_url_expires_at = ! empty($genData['image_url_expires_at'])
                ? Carbon::parse($genData['image_url_expires_at'])
                : null;
            $generation->usage = $genData['usage'] ?? [];
            $generation->rates = $genData['rates'] ?? ($generation->rates ?? PricingCalculator::DEFAULT_RATES);
            $generation->completed_at = ! empty($genData['completed_at'])
                ? Carbon::parse($genData['completed_at'])
                : now();

            // Calculate actual costs
            $costs = $this->pricingCalculator->calculateActualCost(
                $generation->usage,
                $generation->rates
            );

            $generation->actual_provider_cost = $costs['provider_cost'];
            $generation->actual_user_cost = $costs['user_cost'];
            $generation->save();

            // Settle user credit balance
            $this->creditLedgerService->settleGeneration($generation, $generation->actual_user_cost);
            return;
        }

        if ($incomingStatus === GenerationStatus::Failed) {
            $generation->status = GenerationStatus::Failed;
            $generation->error_code = $genData['error']['code'] ?? 'generation_failed';
            $generation->error_message = $genData['error']['message'] ?? 'Generation failed.';
            $generation->completed_at = ! empty($genData['completed_at'])
                ? Carbon::parse($genData['completed_at'])
                : now();
            $generation->actual_provider_cost = '0.00000000';
            $generation->actual_user_cost = '0.00000000';
            $generation->save();

            // Refund user credit balance 100%
            $this->creditLedgerService->refundGeneration($generation, $generation->error_message);
        }
    }
}
