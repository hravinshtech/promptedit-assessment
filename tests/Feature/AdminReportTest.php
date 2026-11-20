<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\PricingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@promptedit.com',
            'is_admin' => true,
        ]);

        $this->regularUser = User::factory()->create([
            'email' => 'user@promptedit.com',
            'is_admin' => false,
        ]);
    }

    public function test_non_admin_users_cannot_access_operator_report(): void
    {
        $response = $this->actingAs($this->regularUser)->get(route('admin.report'));
        $response->assertStatus(403);
    }

    public function test_admin_user_can_view_operator_report_aggregates(): void
    {
        // Create completed generation for testing aggregates
        Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->regularUser->id,
            'pixelforge_id' => 'gen_report_01',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'Admin report test image',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'status' => GenerationStatus::Succeeded,
            'rates' => PricingCalculator::DEFAULT_RATES,
            'actual_provider_cost' => '0.04000000',
            'actual_user_cost' => '0.05000000', // 0.04 * 1.25
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.report'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->has('dailyStats')
            ->has('userStats')
            ->has('summary')
            ->where('summary.total_generations', 1)
        );
    }

    public function test_admin_reconciliation_compares_with_pixelforge_usage_api(): void
    {
        $today = now()->format('Y-m-d');

        // Create generation completed today with provider cost 0.04
        Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->regularUser->id,
            'pixelforge_id' => 'gen_reconcile_01',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'Reconciliation test',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'status' => GenerationStatus::Succeeded,
            'actual_provider_cost' => '0.04000000',
            'actual_user_cost' => '0.05000000',
            'completed_at' => now(),
        ]);

        // Mock Pixelforge GET /v1/usage endpoint
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => $today,
                'end_date' => $today,
                'data' => [
                    [
                        'date' => $today,
                        'generations' => 1,
                        'input_text_tokens' => 10,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1000,
                        'reasoning_tokens' => 0,
                        'cost_usd' => '0.040000000',
                    ],
                ],
                'total' => [
                    'generations' => 1,
                    'cost_usd' => '0.040000000',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.reconcile'), [
            'start_date' => $today,
            'end_date' => $today,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'overall_matched' => true,
        ]);
    }
}
