<?php

namespace App\Messaging;

use App\Contracts\MessagingProvider;
use App\Messaging\Evolution\EvolutionClient;
use App\Models\Store;

/**
 * Evolution API (v2): one instance per store, one server for the whole installation.
 */
final class EvolutionMessagingProvider implements MessagingProvider
{
    public function __construct(private readonly EvolutionClient $client) {}

    public function sendText(Store $store, string $phone, string $text): void
    {
        if (! $this->isConfiguredFor($store)) {
            throw new MessagingException('WhatsApp não conectado para esta loja.');
        }

        $this->client->sendText((string) $store->whatsapp_instance, $phone, $text);
    }

    public function isConfiguredFor(Store $store): bool
    {
        return $this->client->isConfigured() && filled($store->whatsapp_instance);
    }
}
