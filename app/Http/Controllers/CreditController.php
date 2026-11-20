<?php

namespace App\Http\Controllers;

use App\Http\Requests\TopupCreditRequest;
use App\Services\Billing\CreditLedgerService;
use Illuminate\Http\RedirectResponse;

class CreditController extends Controller
{
    public function __construct(
        protected CreditLedgerService $creditLedgerService
    ) {}

    /**
     * Handle credit balance top-up (simulated instant purchase).
     */
    public function topup(TopupCreditRequest $request): RedirectResponse
    {
        $amount = number_format((float) $request->validated('amount'), 2, '.', '');

        $this->creditLedgerService->topup(
            $request->user(),
            $amount,
            sprintf('Purchased $%s credits (simulated purchase)', $amount)
        );

        return back()->with('success', sprintf('Successfully added $%s credits to your balance!', $amount));
    }
}
