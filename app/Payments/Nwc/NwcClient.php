<?php

namespace App\Payments\Nwc;

use App\Payments\PaymentException;

/**
 * Asks the store's Lightning wallet something through Nostr Wallet Connect and waits for the answer.
 */
interface NwcClient
{
    /**
     * What the wallet can do and how it encrypts.
     *
     * @return array{methods: list<string>, encryptions: list<string>}
     *
     * @throws PaymentException
     */
    public function info(NwcConnection $connection): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the "result" of the answer
     *
     * @throws PaymentException
     */
    public function request(NwcConnection $connection, string $method, array $params, string $encryption): array;
}
