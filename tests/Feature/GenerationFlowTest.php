<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\PricingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'credit_balance' => '20.00000000',
        ]);
    }

    public function test_user_can_submit_generation_and_funds_are_held(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
                'rates' => PricingCalculator::DEFAULT_RATES,
            ], 200),
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_fake_123',
                'object' => 'generation',
                'status' => 'queued',
                'prompt' => 'A futuristic floating city',
                'size' => '1024x1024',
                'quality' => 'medium',
                'rates' => PricingCalculator::DEFAULT_RATES,
                'created_at' => now()->toIso8601String(),
            ], 202),
        ]);

        $response = $this->actingAs($this->user)->post(route('generations.store'), [
            'prompt' => 'A futuristic floating city',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ]);

        $response->assertRedirect(route('generations.index'));

        // Verify generation created
        $this->assertDatabaseHas('generations', [
            'user_id' => $this->user->id,
            'pixelforge_id' => 'gen_fake_123',
            'status' => 'queued',
        ]);

        // Verify credit hold logged
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $this->user->id,
            'type' => 'generation_hold',
        ]);

        // User balance must have been reduced by hold amount
        $this->user->refresh();
        $this->assertLessThan('20.00000000', (string) $this->user->credit_balance);
    }

    public function test_content_policy_violation_refunds_hold_immediately(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
                'rates' => PricingCalculator::DEFAULT_RATES,
            ], 200),
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'code' => 'content_policy_violation',
                    'type' => 'invalid_request_error',
                    'message' => 'The prompt was rejected. Nothing was created or billed.',
                ],
            ], 400),
        ]);

        $response = $this->actingAs($this->user)->post(route('generations.store'), [
            'prompt' => 'Explicit disallowed prompt [blocked]',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertSessionHas('error');

        // User balance must be fully refunded to original $20.00
        $this->user->refresh();
        $this->assertSame('20.00000000', (string) $this->user->credit_balance);

        // Verify generation marked as failed and $0 cost
        $this->assertDatabaseHas('generations', [
            'user_id' => $this->user->id,
            'status' => 'failed',
            'actual_user_cost' => '0.00000000',
        ]);
    }
}
