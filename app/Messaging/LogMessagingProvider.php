<?php

namespace App\Messaging;

use App\Contracts\MessagingProvider;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes the message to the log instead of sending it.
 */
final class LogMessagingProvider implements MessagingProvider
{
    public function sendText(Store $store, string $phone, string $text): void
    {
        Log::info("[WhatsApp:{$store->slug}] para {$phone}:\n{$text}");
    }

    public function isConfiguredFor(Store $store): bool
    {
        return true;
    }
}
