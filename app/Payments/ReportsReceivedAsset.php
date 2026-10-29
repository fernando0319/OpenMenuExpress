<?php

namespace App\Payments;

use App\Models\OnlinePayment;
use App\Models\PaymentAccount;

/**
 * A gateway whose charges are priced in reais but paid in another asset (bitcoin), and that can
 * tell how much of it arrived. The ledger keeps that quantity next to the value in reais.
 */
interface ReportsReceivedAsset
{
    /**
     * @throws PaymentException
     */
    public function receivedAsset(PaymentAccount $account, OnlinePayment $payment): ?ReceivedAsset;
}
