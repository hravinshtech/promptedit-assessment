<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(
        protected PixelforgeClient $pixelforgeClient
    ) {}

    /**
     * Operator report dashboard showing credit spend, provider costs, and profit.
     */
    public function index(Request $request): Response
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Unauthorized. Admin access required.');
        }

        $startDate = $request->input('start_date', Carbon::now('UTC')->subDays(29)->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now('UTC')->format('Y-m-d'));

        // 1. Fetch completed generations in range (UTC)
        $generations = Generation::with('user')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                Carbon::parse($startDate, 'UTC')->startOfDay(),
                Carbon::parse($endDate, 'UTC')->endOfDay(),
            ])
            ->get();

        // 2. Aggregate Daily stats (grouped by UTC completion date)
        $dailyGrouped = $generations->groupBy(function ($gen) {
            return $gen->completed_at ? $gen->completed_at->setTimezone('UTC')->format('Y-m-d') : '';
        });

        $dailyStats = [];
        $current = Carbon::parse($startDate, 'UTC');
        $end = Carbon::parse($endDate, 'UTC');

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $dayGens = $dailyGrouped->get($dateStr, collect());

            $dayProviderCost = '0.00000000';
            $dayCreditSpend = '0.00000000';
            $succeededCount = 0;
            $failedCount = 0;

            foreach ($dayGens as $gen) {
                if ($gen->status === GenerationStatus::Succeeded) {
                    $dayProviderCost = bcadd($dayProviderCost, (string) $gen->actual_provider_cost, 8);
                    $dayCreditSpend = bcadd($dayCreditSpend, (string) $gen->actual_user_cost, 8);
                    $succeededCount++;
                } else {
                    $failedCount++;
                }
            }

            $profit = bcsub($dayCreditSpend, $dayProviderCost, 8);
            $margin = (float) $dayCreditSpend > 0
                ? round(((float) $profit / (float) $dayCreditSpend) * 100, 1)
                : 0.0;

            $dailyStats[] = [
                'date' => $dateStr,
                'total_generations' => $dayGens->count(),
                'succeeded' => $succeededCount,
                'failed' => $failedCount,
                'provider_cost' => $dayProviderCost,
                'credit_spend' => $dayCreditSpend,
                'profit' => $profit,
                'margin_percent' => $margin,
            ];

            $current->addDay();
        }

        // Sort newest date first for display
        $dailyStats = array_reverse($dailyStats);

        // 3. Aggregate Per-User stats
        $users = User::with(['generations' => function ($query) use ($startDate, $endDate) {
            $query->whereNotNull('completed_at')
                ->whereBetween('completed_at', [
                    Carbon::parse($startDate, 'UTC')->startOfDay(),
                    Carbon::parse($endDate, 'UTC')->endOfDay(),
                ]);
        }])->get();

        $userStats = $users->map(function (User $u) {
            $userProviderCost = '0.00000000';
            $userCreditSpend = '0.00000000';
            $succeeded = 0;
            $failed = 0;

            foreach ($u->generations as $gen) {
                if ($gen->status === GenerationStatus::Succeeded) {
                    $userProviderCost = bcadd($userProviderCost, (string) $gen->actual_provider_cost, 8);
                    $userCreditSpend = bcadd($userCreditSpend, (string) $gen->actual_user_cost, 8);
                    $succeeded++;
                } else {
                    $failed++;
                }
            }

            $profit = bcsub($userCreditSpend, $userProviderCost, 8);

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'credit_balance' => $u->credit_balance,
                'total_generations' => $u->generations->count(),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'provider_cost' => $userProviderCost,
                'credit_spend' => $userCreditSpend,
                'profit' => $profit,
            ];
        })->sortByDesc('credit_spend')->values()->all();

        // 4. Overall totals
        $totalProviderCost = '0.00000000';
        $totalCreditSpend = '0.00000000';
        foreach ($dailyStats as $day) {
            $totalProviderCost = bcadd($totalProviderCost, $day['provider_cost'], 8);
            $totalCreditSpend = bcadd($totalCreditSpend, $day['credit_spend'], 8);
        }
        $totalProfit = bcsub($totalCreditSpend, $totalProviderCost, 8);
        $totalMargin = (float) $totalCreditSpend > 0
            ? round(((float) $totalProfit / (float) $totalCreditSpend) * 100, 1)
            : 0.0;

        return Inertia::render('Admin/Report', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dailyStats' => $dailyStats,
            'userStats' => $userStats,
            'summary' => [
                'total_generations' => $generations->count(),
                'provider_cost' => $totalProviderCost,
                'credit_spend' => $totalCreditSpend,
                'profit' => $totalProfit,
                'margin_percent' => $totalMargin,
            ],
        ]);
    }

    /**
     * Reconcile local provider costs against Pixelforge GET /v1/usage report.
     */
    public function reconcile(Request $request): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $startDate = $request->input('start_date', Carbon::now('UTC')->subDays(29)->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now('UTC')->format('Y-m-d'));

        try {
            $pixelforgeUsage = $this->pixelforgeClient->getUsage($startDate, $endDate);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => "Failed to reach Pixelforge usage API: {$e->getMessage()}",
            ], 500);
        }

        // Map Pixelforge usage by date
        $remoteByDate = [];
        foreach ($pixelforgeUsage['data'] ?? [] as $entry) {
            $remoteByDate[$entry['date']] = $entry;
        }

        // Fetch our local succeeded generations by date
        $generations = Generation::where('status', GenerationStatus::Succeeded)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                Carbon::parse($startDate, 'UTC')->startOfDay(),
                Carbon::parse($endDate, 'UTC')->endOfDay(),
            ])
            ->get();

        $localByDate = [];
        foreach ($generations as $gen) {
            $date = $gen->completed_at->setTimezone('UTC')->format('Y-m-d');
            $localByDate[$date] = bcadd($localByDate[$date] ?? '0.00000000', (string) $gen->actual_provider_cost, 8);
        }

        $comparison = [];
        $totalLocal = '0.00000000';
        $totalRemote = (string) ($pixelforgeUsage['total']['cost_usd'] ?? '0.00000000');

        foreach ($remoteByDate as $date => $remote) {
            $remoteCost = (string) ($remote['cost_usd'] ?? '0.00000000');
            $localCost = $localByDate[$date] ?? '0.00000000';
            $totalLocal = bcadd($totalLocal, $localCost, 8);

            $diff = bcsub($localCost, $remoteCost, 8);
            $isMatched = bccomp(abs((float) $diff), '0.00001', 5) <= 0;

            $comparison[] = [
                'date' => $date,
                'remote_generations' => $remote['generations'] ?? 0,
                'remote_cost' => $remoteCost,
                'local_cost' => $localCost,
                'diff' => $diff,
                'matched' => $isMatched,
            ];
        }

        $overallDiff = bcsub($totalLocal, $totalRemote, 8);
        $overallMatched = bccomp(abs((float) $overallDiff), '0.00001', 5) <= 0;

        return response()->json([
            'success' => true,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'overall_matched' => $overallMatched,
            'total_local_cost' => $totalLocal,
            'total_remote_cost' => $totalRemote,
            'overall_diff' => $overallDiff,
            'details' => $comparison,
        ]);
    }
}
