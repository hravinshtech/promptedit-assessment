<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedgerService;
use App\Services\Billing\PricingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected CreditLedgerService $ledger;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new CreditLedgerService();
        $this->user = User::factory()->create([
            'credit_balance' => '5.00000000',
        ]);
    }

    public function test_user_can_top_up_credits(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('credits.topup'), [
                'amount' => '25.00',
            ]);

        $response->assertRedirect();
        $this->user->refresh();

        // 5.00 + 25.00 = 30.00
        $this->assertSame('30.00000000', (string) $this->user->credit_balance);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $this->user->id,
            'type' => 'topup',
            'amount' => '25.00',
            'balance_after' => '30.00000000',
        ]);
    }

    public function test_blocks_generation_when_user_has_insufficient_balance(): void
    {
        $this->user->credit_balance = '0.01000000';
        $this->user->save();

        $generation = Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'Expensive generation',
            'size' => Size::Square,
            'quality' => Quality::High,
            'status' => GenerationStatus::Queued,
            'estimated_user_cost' => '0.50000000',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient balance');

        $this->ledger->holdForGeneration($this->user, $generation, '0.50000000');
    }

    public function test_hold_deducts_balance_and_settle_refunds_excess_held(): void
    {
        $generation = Generation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'Testing hold and settle',
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'status' => GenerationStatus::Queued,
            'estimated_user_cost' => '0.10000000',
        ]);

        // 1. Initial Hold: Hold $0.10 from $5.00 -> balance becomes $4.90
        $this->ledger->holdForGeneration($this->user, $generation, '0.10000000');

        $this->user->refresh();
        $this->assertSame('4.90000000', (string) $this->user->credit_balance);

        // 2. Settlement: Actual usage turned out to be cheaper: $0.075
        // Held $0.10, actual $0.075 -> difference $0.025 refunded to balance
        // New balance: 4.90 + 0.025 = 4.925
        $this->ledger->settleGeneration($generation, '0.07500000');

        $this->user->refresh();
        $this->assertSame('4.92500000', (string) $this->user->credit_balance);
    }
}
