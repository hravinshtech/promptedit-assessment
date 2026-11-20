<?php

namespace App\Services\Billing;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreditLedgerService
{
    /**
     * Top up a user's credit balance.
     */
    public function topup(User $user, string $amount, string $description = 'Credit purchase'): CreditTransaction
    {
        if (bccomp($amount, '0.00', 8) <= 0) {
            throw new InvalidArgumentException('Top-up amount must be greater than zero.');
        }

        return DB::transaction(function () use ($user, $amount, $description) {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $newBalance = bcadd($lockedUser->credit_balance, $amount, 8);
            $lockedUser->credit_balance = $newBalance;
            $lockedUser->save();

            return CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'type' => CreditTransactionType::Topup,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => null,
                'reference_id' => null,
                'description' => $description,
            ]);
        });
    }

    /**
     * Reserve funds (hold) when a generation request is submitted.
     */
    public function holdForGeneration(User $user, Generation $generation, string $estimatedAmount): CreditTransaction
    {
        return DB::transaction(function () use ($user, $generation, $estimatedAmount) {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if (bccomp($lockedUser->credit_balance, $estimatedAmount, 8) < 0) {
                throw new InvalidArgumentException(sprintf(
                    'Insufficient balance: required $%s, available $%s.',
                    number_format((float) $estimatedAmount, 4),
                    number_format((float) $lockedUser->credit_balance, 4)
                ));
            }

            $newBalance = bcsub($lockedUser->credit_balance, $estimatedAmount, 8);
            $lockedUser->credit_balance = $newBalance;
            $lockedUser->save();

            $holdAmount = '-' . ltrim($estimatedAmount, '-');

            return CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'type' => CreditTransactionType::GenerationHold,
                'amount' => $holdAmount,
                'balance_after' => $newBalance,
                'reference_type' => Generation::class,
                'reference_id' => $generation->id,
                'description' => sprintf('Hold for generation %s', substr($generation->id, 0, 8)),
            ]);
        });
    }

    /**
     * Settle generation cost once Pixelforge returns the actual token usage.
     * Failed generations cost nothing and must be handled via refundGeneration().
     */
    public function settleGeneration(Generation $generation, string $actualAmount): ?CreditTransaction
    {
        return DB::transaction(function () use ($generation, $actualAmount) {
            // Guard: ensure we haven't already settled or refunded this generation
            $alreadySettled = CreditTransaction::where('reference_id', $generation->id)
                ->whereIn('type', [
                    CreditTransactionType::GenerationSettle,
                    CreditTransactionType::GenerationRefund,
                ])
                ->exists();

            if ($alreadySettled) {
                return null;
            }

            /** @var User $lockedUser */
            $lockedUser = User::where('id', $generation->user_id)->lockForUpdate()->firstOrFail();

            $estimatedAmount = (string) $generation->estimated_user_cost;

            // Adjustment = estimated - actual
            // If positive: we held too much, refund the difference.
            // If negative: actual exceeded estimate, deduct the difference.
            $difference = bcsub($estimatedAmount, $actualAmount, 8);

            $newBalance = bcadd($lockedUser->credit_balance, $difference, 8);
            $lockedUser->credit_balance = $newBalance;
            $lockedUser->save();

            return CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'type' => CreditTransactionType::GenerationSettle,
                'amount' => $difference,
                'balance_after' => $newBalance,
                'reference_type' => Generation::class,
                'reference_id' => $generation->id,
                'description' => sprintf(
                    'Settled generation %s (Held: $%s, Actual: $%s)',
                    substr($generation->id, 0, 8),
                    number_format((float) $estimatedAmount, 4),
                    number_format((float) $actualAmount, 4)
                ),
            ]);
        });
    }

    /**
     * Release hold and refund 100% of held credit when a generation fails.
     */
    public function refundGeneration(Generation $generation, string $reason = 'Generation failed'): ?CreditTransaction
    {
        return DB::transaction(function () use ($generation, $reason) {
            $alreadyHandled = CreditTransaction::where('reference_id', $generation->id)
                ->whereIn('type', [
                    CreditTransactionType::GenerationSettle,
                    CreditTransactionType::GenerationRefund,
                ])
                ->exists();

            if ($alreadyHandled) {
                return null;
            }

            /** @var User $lockedUser */
            $lockedUser = User::where('id', $generation->user_id)->lockForUpdate()->firstOrFail();

            $refundAmount = (string) $generation->estimated_user_cost;

            // If nothing was held, no refund transaction needed
            if (bccomp($refundAmount, '0.00', 8) <= 0) {
                return null;
            }

            $newBalance = bcadd($lockedUser->credit_balance, $refundAmount, 8);
            $lockedUser->credit_balance = $newBalance;
            $lockedUser->save();

            return CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'type' => CreditTransactionType::GenerationRefund,
                'amount' => $refundAmount,
                'balance_after' => $newBalance,
                'reference_type' => Generation::class,
                'reference_id' => $generation->id,
                'description' => sprintf(
                    'Refund for failed generation %s (%s)',
                    substr($generation->id, 0, 8),
                    $reason
                ),
            ]);
        });
    }
}
