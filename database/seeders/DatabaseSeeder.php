<?php

namespace Database\Seeders;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\PricingCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Operator / Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@promptedit.com'],
            [
                'name' => 'PromptEdit Admin',
                'password' => Hash::make('password'),
                'credit_balance' => '50.00000000',
                'is_admin' => true,
            ]
        );

        // 2. Standard Demo User
        $user = User::firstOrCreate(
            ['email' => 'demo@promptedit.com'],
            [
                'name' => 'Demo Designer',
                'password' => Hash::make('password'),
                'credit_balance' => '25.00000000',
                'is_admin' => false,
            ]
        );

        // Record initial topup credit transactions for audit history
        CreditTransaction::create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'type' => CreditTransactionType::Topup,
            'amount' => '50.00000000',
            'balance_after' => '50.00000000',
            'description' => 'Initial welcome credit top-up',
        ]);

        CreditTransaction::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => CreditTransactionType::Topup,
            'amount' => '25.00000000',
            'balance_after' => '25.00000000',
            'description' => 'Initial welcome credit top-up',
        ]);

        $calculator = new PricingCalculator();

        // 3. Sample Succeeded Generation (Worked example from Pixelforge docs)
        // High quality, 1536x1024, 2 reference images
        $samplePrompt = 'A lighthouse on a cliff at dawn, oil painting';
        $usage = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ];
        $costs = $calculator->calculateActualCost($usage, PricingCalculator::DEFAULT_RATES);

        Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $samplePrompt,
            'size' => Size::Landscape,
            'quality' => Quality::High,
            'reference_images' => ['https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=800&q=80'],
            'status' => GenerationStatus::Succeeded,
            'image_url' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1536&q=80',
            'image_url_expires_at' => now()->addHours(24),
            'usage' => $usage,
            'rates' => PricingCalculator::DEFAULT_RATES,
            'estimated_provider_cost' => '0.37387500',
            'estimated_user_cost' => '0.46734375',
            'actual_provider_cost' => $costs['provider_cost'],
            'actual_user_cost' => $costs['user_cost'],
            'submitted_at' => now()->subMinutes(15),
            'started_at' => now()->subMinutes(14),
            'completed_at' => now()->subMinutes(13),
        ]);

        // 4. Sample Failed Generation (Unbilled $0)
        Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'pixelforge_id' => 'gen_01JC2Z9FAILED00000000000',
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'An abstract geometric sculpture with [fail] directive',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'status' => GenerationStatus::Failed,
            'image_url' => null,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for the prompt.',
            'rates' => PricingCalculator::DEFAULT_RATES,
            'estimated_provider_cost' => '0.05000000',
            'estimated_user_cost' => '0.06250000',
            'actual_provider_cost' => '0.00000000',
            'actual_user_cost' => '0.00000000',
            'submitted_at' => now()->subMinutes(30),
            'started_at' => now()->subMinutes(29),
            'completed_at' => now()->subMinutes(28),
        ]);
    }
}
