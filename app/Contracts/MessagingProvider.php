<?php

namespace App\Contracts;

use App\Messaging\MessagingException;
use App\Models\Store;

/**
 * Sends a WhatsApp text on behalf of a store. Implementations: Evolution API, Meta Cloud API, Z-API…
 * Chosen by config('messaging.driver').
 */
interface MessagingProvider
{
    /**
     * @param  string  $phone  Digits with country code, e.g. 5511999998888.
     *
     * @throws MessagingException when the provider refuses or cannot be reached.
     */
    public function sendText(Store $store, string $phone, string $text): void;

    /**
     * Whether this store has what the provider needs to send (instance, credentials…).
     */
    public function isConfiguredFor(Store $store): bool;
}
