<?php

namespace App\Services\Billing;

use App\Enums\Quality;
use App\Enums\Size;

class PricingCalculator
{
    public const DEFAULT_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public const MARKUP_MULTIPLIER = '1.25'; // 25% markup
    public const TOKENS_PER_IMAGE = 765;
    public const CHARS_PER_TEXT_TOKEN = 4;

    /**
     * Estimate token usage and costs before a generation is submitted.
     */
    public function estimate(
        string $prompt,
        Size $size,
        Quality $quality,
        array $referenceImages = [],
        ?array $rates = null
    ): array {
        $rates = $rates ?? self::DEFAULT_RATES;

        // 1. Input text tokens: ceil(characters / 4)
        $charCount = mb_strlen($prompt, 'UTF-8');
        $estimatedTextTokens = (int) ceil($charCount / self::CHARS_PER_TEXT_TOKEN);

        // Text variance is +/- 15%
        $minTextTokens = max(1, (int) floor($estimatedTextTokens * 0.85));
        $maxTextTokens = (int) ceil($estimatedTextTokens * 1.15);

        // 2. Input image tokens: 765 per reference image
        $imageTokens = count($referenceImages) * self::TOKENS_PER_IMAGE;

        // 3. Output tokens: exact lookup
        $outputTokens = $size->outputTokens($quality);

        // 4. Reasoning tokens range
        $reasoningRange = $quality->reasoningTokenRange();
        $minReasoning = $reasoningRange['min'];
        $maxReasoning = $reasoningRange['max'];
        $estReasoning = $reasoningRange['estimate'];

        // Calculate provider costs (min, expected, max)
        $minProviderCost = $this->calculateTokenCost(
            $minTextTokens,
            $imageTokens,
            $outputTokens,
            $minReasoning,
            $rates
        );

        $expectedProviderCost = $this->calculateTokenCost(
            $estimatedTextTokens,
            $imageTokens,
            $outputTokens,
            $estReasoning,
            $rates
        );

        $maxProviderCost = $this->calculateTokenCost(
            $maxTextTokens,
            $imageTokens,
            $outputTokens,
            $maxReasoning,
            $rates
        );

        // Calculate user prices (+25% markup)
        $minUserCost = $this->applyMarkup($minProviderCost);
        $expectedUserCost = $this->applyMarkup($expectedProviderCost);
        $maxUserCost = $this->applyMarkup($maxProviderCost);

        return [
            'tokens' => [
                'input_text' => [
                    'estimated' => $estimatedTextTokens,
                    'min' => $minTextTokens,
                    'max' => $maxTextTokens,
                ],
                'input_image' => $imageTokens,
                'output' => $outputTokens,
                'reasoning' => [
                    'estimated' => $estReasoning,
                    'min' => $minReasoning,
                    'max' => $maxReasoning,
                ],
                'total_estimated' => $estimatedTextTokens + $imageTokens + $outputTokens + $estReasoning,
            ],
            'rates' => $rates,
            'provider_cost' => [
                'min' => $minProviderCost,
                'estimated' => $expectedProviderCost,
                'max' => $maxProviderCost,
            ],
            'user_cost' => [
                'min' => $minUserCost,
                'estimated' => $expectedUserCost,
                'max' => $maxUserCost,
            ],
        ];
    }

    /**
     * Calculate actual provider cost and user price from a completed generation's usage.
     */
    public function calculateActualCost(array $usage, array $rates): array
    {
        $textTokens = (int) ($usage['input_text_tokens'] ?? 0);
        $imageTokens = (int) ($usage['input_image_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $reasoningTokens = (int) ($usage['reasoning_tokens'] ?? 0);

        $providerCost = $this->calculateTokenCost(
            $textTokens,
            $imageTokens,
            $outputTokens,
            $reasoningTokens,
            $rates
        );

        $userCost = $this->applyMarkup($providerCost);

        return [
            'provider_cost' => $providerCost,
            'user_cost' => $userCost,
        ];
    }

    /**
     * Exact token cost formula:
     * (text * rate_text + image * rate_image + output * rate_output + reasoning * rate_reasoning) / 1,000,000
     * Computed with bcmath up to 9 decimal places to eliminate float drift.
     */
    public function calculateTokenCost(
        int $textTokens,
        int $imageTokens,
        int $outputTokens,
        int $reasoningTokens,
        array $rates
    ): string {
        $rateText = (string) ($rates['input_text_tokens'] ?? self::DEFAULT_RATES['input_text_tokens']);
        $rateImage = (string) ($rates['input_image_tokens'] ?? self::DEFAULT_RATES['input_image_tokens']);
        $rateOutput = (string) ($rates['output_tokens'] ?? self::DEFAULT_RATES['output_tokens']);
        $rateReasoning = (string) ($rates['reasoning_tokens'] ?? self::DEFAULT_RATES['reasoning_tokens']);

        $costText = bcmul((string) $textTokens, $rateText, 9);
        $costImage = bcmul((string) $imageTokens, $rateImage, 9);
        $costOutput = bcmul((string) $outputTokens, $rateOutput, 9);
        $costReasoning = bcmul((string) $reasoningTokens, $rateReasoning, 9);

        $sum = bcadd($costText, $costImage, 9);
        $sum = bcadd($sum, $costOutput, 9);
        $sum = bcadd($sum, $costReasoning, 9);

        // Divide by 1,000,000
        return bcdiv($sum, '1000000', 9);
    }

    /**
     * Apply 25% markup: amount * 1.25.
     */
    public function applyMarkup(string $cost): string
    {
        return bcmul($cost, self::MARKUP_MULTIPLIER, 9);
    }
}
