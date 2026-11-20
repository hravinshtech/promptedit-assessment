<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Exceptions\PixelforgeException;
use App\Http\Requests\CreateGenerationRequest;
use App\Models\Generation;
use App\Services\Billing\PricingCalculator;
use App\Services\Pixelforge\GenerationService;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class GenerationController extends Controller
{
    public function __construct(
        protected GenerationService $generationService,
        protected PricingCalculator $pricingCalculator,
        protected PixelforgeClient $pixelforgeClient
    ) {}

    /**
     * Display a listing of the user's generations.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $user = $request->user();

        // If client polls for pending updates via JSON
        if ($request->wantsJson() || $request->boolean('json')) {
            $pending = $user->generations()
                ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
                ->get();

            // Try to sync any stuck pending generations if they have a pixelforge_id
            foreach ($pending as $generation) {
                $this->generationService->syncGeneration($generation);
            }

            return response()->json([
                'generations' => $user->generations()->latest('created_at')->take(20)->get(),
                'credit_balance' => $user->fresh()->credit_balance,
            ]);
        }

        $generations = $user->generations()
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();

        $pricing = $this->pixelforgeClient->getPricing();

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
            'rates' => $pricing['rates'] ?? PricingCalculator::DEFAULT_RATES,
            'pendingCount' => $user->generations()
                ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
                ->count(),
        ]);
    }

    /**
     * Show the form for creating a new generation.
     */
    public function create(Request $request): Response
    {
        $pricing = $this->pixelforgeClient->getPricing();

        return Inertia::render('Generations/Create', [
            'rates' => $pricing['rates'] ?? PricingCalculator::DEFAULT_RATES,
            'sizes' => array_column(Size::cases(), 'value'),
            'qualities' => array_column(Quality::cases(), 'value'),
        ]);
    }

    /**
     * Live cost estimation endpoint for up-front pricing preview.
     */
    public function estimate(Request $request): JsonResponse
    {
        $prompt = (string) $request->input('prompt', '');
        $sizeValue = (string) $request->input('size', Size::Square->value);
        $qualityValue = (string) $request->input('quality', Quality::Medium->value);
        $referenceImages = (array) $request->input('reference_images', []);

        $size = Size::tryFrom($sizeValue) ?? Size::Square;
        $quality = Quality::tryFrom($qualityValue) ?? Quality::Medium;

        $pricing = $this->pixelforgeClient->getPricing();
        $rates = $pricing['rates'] ?? PricingCalculator::DEFAULT_RATES;

        $estimate = $this->pricingCalculator->estimate($prompt, $size, $quality, $referenceImages, $rates);

        return response()->json($estimate);
    }

    /**
     * Store a newly created generation request.
     */
    public function store(CreateGenerationRequest $request): RedirectResponse
    {
        try {
            $this->generationService->create(
                $request->user(),
                $request->validated()
            );

            return redirect()->route('generations.index')
                ->with('success', 'Generation request queued successfully!');
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (PixelforgeException $e) {
            $msg = match ($e->getErrorCode()) {
                'content_policy_violation' => 'Your prompt was rejected by Pixelforge content policy. You were not charged.',
                'concurrency_limit_exceeded' => 'Pixelforge concurrency limit reached. Please wait a few seconds and retry.',
                'daily_limit_exceeded' => 'Pixelforge daily generation quota reached for today.',
                'rate_limit_exceeded' => 'Too many requests. Please wait a moment before trying again.',
                'overloaded' => 'Pixelforge is temporarily overloaded. Please retry.',
                default => "Pixelforge error: {$e->getMessage()}",
            };

            return back()->withInput()->with('error', $msg);
        }
    }

    /**
     * Manual or periodic sync for a specific generation.
     */
    public function sync(Request $request, Generation $generation): JsonResponse
    {
        if ($generation->user_id !== $request->user()->id) {
            abort(403);
        }

        $synced = $this->generationService->syncGeneration($generation);

        return response()->json([
            'generation' => $synced,
            'credit_balance' => $request->user()->fresh()->credit_balance,
        ]);
    }
}
