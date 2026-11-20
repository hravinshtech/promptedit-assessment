<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeException;
use App\Services\Pixelforge\GenerationService;
use App\Services\Pixelforge\WebhookSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookSignatureValidator $validator,
        protected GenerationService $generationService
    ) {}

    /**
     * Handle incoming Pixelforge webhooks.
     * Verifies signature against raw payload before JSON decoding.
     */
    public function __invoke(Request $request): Response
    {
        $rawPayload = $request->getContent();
        $signatureHeader = $request->header('Pixelforge-Signature');

        try {
            $this->validator->validate($rawPayload, $signatureHeader);
        } catch (PixelforgeException $e) {
            Log::warning("Pixelforge webhook signature validation failed: {$e->getMessage()}");
            abort($e->getHttpStatus(), $e->getMessage());
        }

        $event = json_decode($rawPayload, true);
        if (! is_array($event)) {
            abort(400, 'Invalid JSON payload.');
        }

        $attempt = (int) $request->header('Pixelforge-Delivery-Attempt', 1);

        $this->generationService->handleWebhook($event, $attempt);

        return response()->noContent();
    }
}
