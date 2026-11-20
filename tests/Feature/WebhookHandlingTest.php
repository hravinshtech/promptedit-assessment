<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\PricingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'whsec_test_secret_key_12345';
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => $this->secret]);

        $this->user = User::factory()->create([
            'credit_balance' => '10.00000000',
        ]);
    }

    protected function generateSignature(string $rawPayload, int $timestamp): string
    {
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $this->secret);
        return "t={$timestamp},v1={$signature}";
    }

    public function test_rejects_requests_with_missing_or_invalid_signature(): void
    {
        $payload = json_encode(['id' => 'evt_1', 'type' => 'ping']);

        // Missing signature
        $response = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload
        );
        $response->assertStatus(400);

        // Invalid signature
        $response = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => 't=' . time() . ',v1=bad_signature_hex',
            ],
            $payload
        );
        $response->assertStatus(400);
    }

    public function test_rejects_expired_timestamp_replay_attacks(): void
    {
        $payload = json_encode(['id' => 'evt_1', 'type' => 'ping']);
        $oldTimestamp = time() - 305; // 5 mins 5 secs ago (>300s tolerance)
        $sig = $this->generateSignature($payload, $oldTimestamp);

        $response = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            ],
            $payload
        );
        $response->assertStatus(400);
    }

    public function test_accepts_valid_ping_webhook(): void
    {
        $payload = json_encode([
            'id' => 'evt_ping_123',
            'object' => 'event',
            'type' => 'ping',
            'created_at' => now()->toIso8601String(),
        ]);
        $timestamp = time();
        $sig = $this->generateSignature($payload, $timestamp);

        $response = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            ],
            $payload
        );
        $response->assertStatus(204);
    }

    public function test_generation_succeeded_event_calculates_actual_cost_and_settles_balance(): void
    {
        // 1. Create a queued generation where user had $0.10 held
        $generation = Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'pixelforge_id' => 'gen_succ_123',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'A calm lake [succeed]',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'status' => GenerationStatus::Queued,
            'rates' => PricingCalculator::DEFAULT_RATES,
            'estimated_provider_cost' => '0.08000000',
            'estimated_user_cost' => '0.10000000',
            'submitted_at' => now()->subMinute(),
        ]);

        // User balance after initial hold: $9.90
        $this->user->credit_balance = '9.90000000';
        $this->user->save();

        $eventPayload = [
            'id' => 'evt_succ_001',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'object' => [
                    'id' => 'gen_succ_123',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/gen_succ_123.png',
                    'image_url_expires_at' => now()->addHours(24)->toIso8601String(),
                    'usage' => [
                        'input_text_tokens' => 10,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 500,
                        'total_tokens' => 1610,
                    ],
                    'rates' => PricingCalculator::DEFAULT_RATES,
                    'completed_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $rawBody = json_encode($eventPayload);
        $timestamp = time();
        $sig = $this->generateSignature($rawBody, $timestamp);

        $response = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $sig,
                'HTTP_PIXELFORGE_EVENT_ID' => 'evt_succ_001',
            ],
            $rawBody
        );

        $response->assertStatus(204);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNotNull($generation->image_url);
        $this->assertGreaterThan('0.00000000', (string) $generation->actual_provider_cost);
        $this->assertGreaterThan('0.00000000', (string) $generation->actual_user_cost);

        // Verify credit transaction recorded
        $this->assertDatabaseHas('credit_transactions', [
            'reference_id' => $generation->id,
            'type' => 'generation_settle',
        ]);

        // Verify webhook event recorded in deduplication table
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_succ_001',
            'event_type' => 'generation.succeeded',
        ]);
    }

    public function test_generation_failed_event_records_zero_cost_and_refunds_hold(): void
    {
        $generation = Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'pixelforge_id' => 'gen_fail_456',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'Failing image [fail]',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'status' => GenerationStatus::Processing,
            'rates' => PricingCalculator::DEFAULT_RATES,
            'estimated_provider_cost' => '0.08000000',
            'estimated_user_cost' => '0.10000000',
            'submitted_at' => now()->subMinute(),
        ]);

        $this->user->credit_balance = '9.90000000';
        $this->user->save();

        $eventPayload = [
            'id' => 'evt_fail_002',
            'object' => 'event',
            'type' => 'generation.failed',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'object' => [
                    'id' => 'gen_fail_456',
                    'object' => 'generation',
                    'status' => 'failed',
                    'error' => [
                        'code' => 'generation_failed',
                        'message' => 'The model could not produce an image for the prompt.',
                    ],
                    'completed_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $rawBody = json_encode($eventPayload);
        $timestamp = time();
        $sig = $this->generateSignature($rawBody, $timestamp);

        $response = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            ],
            $rawBody
        );

        $response->assertStatus(204);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('0.00000000', (string) $generation->actual_provider_cost);
        $this->assertSame('0.00000000', (string) $generation->actual_user_cost);

        // Verify 100% refund of the $0.10 hold restored user balance to $10.00
        $this->user->refresh();
        $this->assertSame('10.00000000', (string) $this->user->credit_balance);

        $this->assertDatabaseHas('credit_transactions', [
            'reference_id' => $generation->id,
            'type' => 'generation_refund',
            'amount' => '0.10000000',
        ]);
    }

    public function test_duplicate_webhook_is_idempotent_and_never_bills_twice(): void
    {
        // Tests [duplicate] directive: identical event arriving twice
        $generation = Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'pixelforge_id' => 'gen_dup_789',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'Duplicate test [duplicate]',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'status' => GenerationStatus::Processing,
            'rates' => PricingCalculator::DEFAULT_RATES,
            'estimated_provider_cost' => '0.08000000',
            'estimated_user_cost' => '0.10000000',
        ]);

        $this->user->credit_balance = '9.90000000';
        $this->user->save();

        $eventPayload = [
            'id' => 'evt_dup_999',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'object' => [
                    'id' => 'gen_dup_789',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/gen_dup_789.png',
                    'usage' => [
                        'input_text_tokens' => 10,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 500,
                        'total_tokens' => 1610,
                    ],
                    'rates' => PricingCalculator::DEFAULT_RATES,
                    'completed_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $rawBody = json_encode($eventPayload);
        $timestamp = time();
        $sig = $this->generateSignature($rawBody, $timestamp);

        // First delivery: attempt 1
        $response1 = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $sig,
                'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
            ],
            $rawBody
        );
        $response1->assertStatus(204);

        $initialTransactionsCount = CreditTransaction::where('reference_id', $generation->id)->count();

        // Second delivery: attempt 2 (exact same event ID)
        $response2 = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $sig,
                'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '2',
            ],
            $rawBody
        );
        $response2->assertStatus(204);

        // Verify credit transaction was NOT added again
        $this->assertSame($initialTransactionsCount, CreditTransaction::where('reference_id', $generation->id)->count());
    }

    public function test_out_of_order_processing_event_does_not_regress_succeeded_state(): void
    {
        // Generation has already succeeded
        $generation = Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'pixelforge_id' => 'gen_ooo_111',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'Out of order test',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'status' => GenerationStatus::Succeeded,
            'image_url' => 'https://pixelforge.promptedit.dev/images/done.png',
            'actual_user_cost' => '0.08000000',
            'completed_at' => now(),
        ]);

        // Delayed generation.processing event arrives later
        $eventPayload = [
            'id' => 'evt_late_processing_555',
            'object' => 'event',
            'type' => 'generation.processing',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'object' => [
                    'id' => 'gen_ooo_111',
                    'object' => 'generation',
                    'status' => 'processing',
                ],
            ],
        ];

        $rawBody = json_encode($eventPayload);
        $timestamp = time();
        $sig = $this->generateSignature($rawBody, $timestamp);

        $response = $this->call(
            'POST',
            route('webhooks.pixelforge'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            ],
            $rawBody
        );

        $response->assertStatus(204);

        // Must remain in final Succeeded status
        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('https://pixelforge.promptedit.dev/images/done.png', $generation->image_url);
    }
}
