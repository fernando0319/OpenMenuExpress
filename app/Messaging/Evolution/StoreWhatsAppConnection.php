<?php

namespace App\Messaging\Evolution;

use App\Messaging\MessagingException;
use App\Models\Store;
use Illuminate\Contracts\Session\Session;

/**
 * Links a store's WhatsApp to its Evolution instance (QR code or pairing code).
 * The settings page polls status(), so no webhook is needed (works on localhost too).
 */
final class StoreWhatsAppConnection
{
    private const PROMPT_TTL_SECONDS = 50;

    public function __construct(private readonly EvolutionClient $client) {}

    public function isAvailable(): bool
    {
        return config('messaging.driver') === 'evolution' && $this->client->isConfigured();
    }

    /**
     * @throws MessagingException
     */
    public function connect(Store $store, ?string $phone, Session $session): void
    {
        $instance = $this->client->instanceNameFor($store->slug);
        $prompt = $this->client->connect($instance, $phone);

        $store->update(['whatsapp_instance' => $instance]);

        $session->put($this->promptKey($store), [
            'qr' => $prompt->qrCodeImage,
            'pairingCode' => $prompt->pairingCode,
            'at' => now()->getTimestamp(),
        ]);
    }

    /**
     * @throws MessagingException
     */
    public function disconnect(Store $store, Session $session): void
    {
        $this->client->logout($this->client->instanceNameFor($store->slug));

        $store->update(['whatsapp_instance' => null]);
        $session->forget($this->promptKey($store));
    }

    /**
     * @return array{state: string, label: string, error?: string, qr?: string|null, pairingCode?: string|null, number?: string|null, profileName?: string|null}|null
     */
    public function status(Store $store, Session $session): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $instance = $this->client->instanceNameFor($store->slug);

        try {
            $state = $this->client->state($instance);
        } catch (MessagingException $exception) {
            return ['state' => 'error', 'label' => 'Servidor do WhatsApp indisponível', 'error' => $exception->getMessage()];
        }

        if ($state === ConnectionState::Open) {
            $session->forget($this->promptKey($store));

            if ($store->whatsapp_instance !== $instance) {
                $store->update(['whatsapp_instance' => $instance]);
            }

            return ['state' => $state->value, 'label' => $state->label(), ...$this->client->profile($instance)];
        }

        $prompt = $session->get($this->promptKey($store));
        $fresh = is_array($prompt) && now()->getTimestamp() - (int) ($prompt['at'] ?? 0) < self::PROMPT_TTL_SECONDS;

        return [
            'state' => $state->value,
            'label' => $state->label(),
            'qr' => $fresh && is_string($prompt['qr'] ?? null) ? $prompt['qr'] : null,
            'pairingCode' => $fresh && is_string($prompt['pairingCode'] ?? null) ? $prompt['pairingCode'] : null,
        ];
    }

    private function promptKey(Store $store): string
    {
        return "whatsapp_prompt.{$store->id}";
    }
}
