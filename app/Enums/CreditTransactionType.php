<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Topup = 'topup';
    case GenerationHold = 'generation_hold';
    case GenerationSettle = 'generation_settle';
    case GenerationRefund = 'generation_refund';
}
