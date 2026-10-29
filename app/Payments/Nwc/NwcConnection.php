<?php

namespace App\Payments\Nwc;

use InvalidArgumentException;
use swentel\nostr\Key\Key;

/**
 * The connection string a Lightning wallet hands out for Nostr Wallet Connect (NIP-47):
 * nostr+walletconnect://<wallet pubkey>?relay=wss://…&secret=<hex>&lud16=…
 *
 * The secret only lets this server ask the wallet for what the wallet allowed (here: create and
 * look up invoices). The money never passes through the server.
 */
final readonly class NwcConnection
{
    /**
     * @param  list<string>  $relays
     */
    private function __construct(
        public string $walletPubkey,
        public array $relays,
        public string $secret,
        public ?string $lud16,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $uri): self
    {
        $uri = trim($uri);

        if (preg_match('#^nostr\+walletconnect://([0-9a-fA-F]{64})\?(.+)$#', $uri, $parts) !== 1) {
            throw new InvalidArgumentException('Cole o código de conexão completo, que começa com nostr+walletconnect://');
        }

        $relays = [];
        $secret = null;
        $lud16 = null;

        // "relay" may repeat, which parse_str would collapse into one.
        foreach (explode('&', $parts[2]) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $value = rawurldecode($value);

            match ($name) {
                'relay' => $relays[] = $value,
                'secret' => $secret = strtolower($value),
                'lud16' => $lud16 = $value,
                default => null,
            };
        }

        $relays = array_values(array_unique(array_filter($relays, fn (string $relay) => preg_match('#^wss?://[^\s]+$#', $relay) === 1)));

        if ($relays === [] || $secret === null || preg_match('/^[0-9a-f]{64}$/', $secret) !== 1) {
            throw new InvalidArgumentException('O código de conexão está incompleto (faltam o relay ou o segredo). Gere outro na carteira.');
        }

        return new self(strtolower($parts[1]), $relays, $secret, $lud16 !== '' ? $lud16 : null);
    }

    /**
     * The key this server signs its requests with, derived from the secret.
     */
    public function clientPubkey(): string
    {
        return (new Key)->getPublicKey($this->secret);
    }

    /**
     * How the wallet shows up for the merchant: its Lightning address or the start of its key.
     */
    public function label(): string
    {
        return $this->lud16 ?? 'Carteira '.substr($this->walletPubkey, 0, 8).'…';
    }
}
