<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeException;

class WebhookSignatureValidator
{
    public const TOLERANCE_SECONDS = 300; // 5-minute replay window

    /**
     * Validate the HMAC-SHA256 signature from the Pixelforge-Signature header.
     *
     * @throws PixelforgeException
     */
    public function validate(string $rawPayload, ?string $signatureHeader, ?string $secret = null): bool
    {
        $rawSecret = $secret ?: (config('services.pixelforge.webhook_secret') ?: getenv('PIXELFORGE_WEBHOOK_SECRET') ?: ($_ENV['PIXELFORGE_WEBHOOK_SECRET'] ?? ''));
        $secret = trim((string) $rawSecret, " \t\n\r\0\x0B\"'");

        if (empty($secret)) {
            throw new PixelforgeException('Webhook secret is not configured.', 'missing_webhook_secret', 500);
        }

        if (empty($signatureHeader)) {
            throw new PixelforgeException('Missing Pixelforge-Signature header.', 'missing_signature', 400);
        }

        $parsed = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $segments = explode('=', trim($part), 2);
            if (count($segments) === 2) {
                $parsed[$segments[0]] = $segments[1];
            }
        }

        if (! isset($parsed['t']) || ! isset($parsed['v1'])) {
            throw new PixelforgeException('Malformed Pixelforge-Signature header format.', 'invalid_signature_header', 400);
        }

        $timestamp = (int) $parsed['t'];
        $signature = $parsed['v1'];

        // Replay attack prevention: reject timestamps older or ahead of tolerance
        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new PixelforgeException('Webhook timestamp out of tolerance window.', 'timestamp_expired', 400);
        }

        // Expected signature: HMAC-SHA256 of "<timestamp>.<rawPayload>"
        $signedPayload = "{$timestamp}.{$rawPayload}";
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new PixelforgeException('Invalid webhook signature verification failed.', 'invalid_signature', 400);
        }

        return true;
    }
}
