<?php

namespace Tests\Feature;

use App\Enums\Quality;
use App\Enums\Size;
use App\Services\Billing\PricingCalculator;
use Tests\TestCase;

class PricingCalculatorTest extends TestCase
{
    protected PricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PricingCalculator();
    }

    public function test_matches_pixelforge_docs_worked_example_exact_cost(): void
    {
        // Worked example from Pixelforge docs:
        // High quality, 1536x1024, 400-char prompt (115 text tokens with variance), 2 reference images (1530 tokens),
        // 6450 output tokens, 8000 max reasoning tokens.
        // Total expected cost = $0.373875000
        $textTokens = 115;
        $imageTokens = 1530;
        $outputTokens = 6450;
        $reasoningTokens = 8000;

        $cost = $this->calculator->calculateTokenCost(
            $textTokens,
            $imageTokens,
            $outputTokens,
            $reasoningTokens,
            PricingCalculator::DEFAULT_RATES
        );

        $this->assertSame('0.373875000', $cost);
    }

    public function test_markup_multiplier_is_exactly_twenty_five_percent(): void
    {
        $baseCost = '0.373875000';
        $userPrice = $this->calculator->applyMarkup($baseCost);

        // 0.373875 * 1.25 = 0.467343750
        $this->assertSame('0.467343750', $userPrice);
    }

    public function test_upfront_estimation_calculates_ranges_and_token_counts(): void
    {
        $prompt = str_repeat('a', 400); // 400 chars -> 100 estimated tokens
        $refImages = ['https://example.com/1.png', 'https://example.com/2.png'];

        $estimate = $this->calculator->estimate(
            $prompt,
            Size::Landscape,
            Quality::High,
            $refImages
        );

        // Text tokens
        $this->assertSame(100, $estimate['tokens']['input_text']['estimated']);
        $this->assertSame(85, $estimate['tokens']['input_text']['min']);
        $this->assertSame(115, $estimate['tokens']['input_text']['max']);

        // Image tokens (2 * 765 = 1530)
        $this->assertSame(1530, $estimate['tokens']['input_image']);

        // Output tokens for high 1536x1024
        $this->assertSame(6450, $estimate['tokens']['output']);

        // Reasoning range for high: 1000 to 8000, estimate 4500
        $this->assertSame(1000, $estimate['tokens']['reasoning']['min']);
        $this->assertSame(8000, $estimate['tokens']['reasoning']['max']);
        $this->assertSame(4500, $estimate['tokens']['reasoning']['estimated']);

        // Max user price must match the 0.467343750 from worked example
        $this->assertSame('0.467343750', $estimate['user_cost']['max']);
    }

    public function test_calculate_actual_cost_from_usage(): void
    {
        $usage = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ];

        $costs = $this->calculator->calculateActualCost($usage, PricingCalculator::DEFAULT_RATES);

        $this->assertNotEmpty($costs['provider_cost']);
        $this->assertNotEmpty($costs['user_cost']);

        // Confirm user cost is provider cost * 1.25
        $expectedUser = bcmul($costs['provider_cost'], '1.25', 9);
        $this->assertSame($expectedUser, $costs['user_cost']);
    }

    public function test_unicode_prompt_counted_by_characters_not_bytes(): void
    {
        // 4 emoji characters (each 4 bytes = 16 bytes, but 4 unicode characters)
        $prompt = '🚀🎉🎨✨';
        $estimate = $this->calculator->estimate(
            $prompt,
            Size::Square,
            Quality::Medium
        );

        // 4 chars / 4 = 1 token
        $this->assertSame(1, $estimate['tokens']['input_text']['estimated']);
    }
}
