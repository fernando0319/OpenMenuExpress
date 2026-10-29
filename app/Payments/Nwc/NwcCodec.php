<?php

namespace App\Payments\Nwc;

use App\Payments\PaymentException;
use swentel\nostr\Encryption\Nip04;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Throwable;

/**
 * The messages of Nostr Wallet Connect (NIP-47), apart from how they travel: a signed and
 * encrypted request (kind 23194) and the wallet's answer to it (kind 23195).
 */
final class NwcCodec
{
    public const REQUEST_KIND = 23194;

    public const RESPONSE_KIND = 23195;

    public const INFO_KIND = 13194;

    public const NIP44 = 'nip44_v2';

    public const NIP04 = 'nip04';

    /**
     * @param  array<string, mixed>  $params
     */
    public static function request(NwcConnection $connection, string $method, array $params, string $encryption): Event
    {
        $payload = (string) json_encode(['method' => $method, 'params' => (object) $params], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tags = [['p', $connection->walletPubkey]];

        if ($encryption === self::NIP44) {
            $tags[] = ['encryption', self::NIP44];
        }

        $event = (new Event)
            ->setKind(self::REQUEST_KIND)
            ->setCreatedAt(time())
            ->setTags($tags)
            ->setContent(self::encrypt($payload, $connection, $encryption));

        (new Sign)->signEvent($event, $connection->secret);

        return $event;
    }

    /**
     * The "result" of the wallet's answer to $requestId.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException when the event is not a genuine answer, or the wallet refused
     */
    public static function response(NwcConnection $connection, object $event, string $requestId): array
    {
        if (! (new Event)->verify($event)
            || ($event->kind ?? null) !== self::RESPONSE_KIND
            || ($event->pubkey ?? null) !== $connection->walletPubkey
            || ! self::hasTag($event, 'e', $requestId)) {
            throw new PaymentException('Resposta da carteira inválida.');
        }

        $content = (string) ($event->content ?? '');
        $json = json_decode(self::decrypt($content, $connection), true);

        if (! is_array($json)) {
            throw new PaymentException('A carteira respondeu algo que não dá para ler.');
        }

        if (is_array($json['error'] ?? null)) {
            $code = (string) ($json['error']['code'] ?? 'OTHER');
            $message = (string) ($json['error']['message'] ?? '');

            throw new PaymentException(trim("A carteira recusou ({$code}). {$message}"), code: $code === 'NOT_FOUND' ? 404 : 0);
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }

    /**
     * What the wallet announced it can do (methods) and how it encrypts.
     *
     * @return array{methods: list<string>, encryptions: list<string>}
     */
    public static function info(NwcConnection $connection, object $event): array
    {
        if (! (new Event)->verify($event) || ($event->kind ?? null) !== self::INFO_KIND || ($event->pubkey ?? null) !== $connection->walletPubkey) {
            throw new PaymentException('A carteira publicou um anúncio inválido.');
        }

        $methods = preg_split('/\s+/', trim((string) ($event->content ?? ''))) ?: [];
        $encryptions = [self::NIP04]; // wallets that say nothing speak nip04

        foreach ((array) ($event->tags ?? []) as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'encryption' && is_string($tag[1] ?? null)) {
                $encryptions = preg_split('/\s+/', trim($tag[1])) ?: $encryptions;
            }
        }

        return ['methods' => array_values(array_filter($methods)), 'encryptions' => array_values(array_filter($encryptions))];
    }

    /**
     * NIP-44 when the wallet speaks it (the current standard), NIP-04 otherwise.
     *
     * @param  list<string>  $encryptions
     */
    public static function preferredEncryption(array $encryptions): string
    {
        return in_array(self::NIP44, $encryptions, true) ? self::NIP44 : self::NIP04;
    }

    public static function encrypt(string $plaintext, NwcConnection $connection, string $encryption): string
    {
        try {
            return $encryption === self::NIP44
                ? Nip44::encrypt($plaintext, Nip44::getConversationKey($connection->secret, $connection->walletPubkey))
                : Nip04::encrypt($plaintext, $connection->secret, $connection->walletPubkey);
        } catch (Throwable $exception) {
            throw new PaymentException('Não foi possível cifrar o pedido para a carteira.', previous: $exception);
        }
    }

    /**
     * The answer comes in the same scheme as the request; NIP-04 is recognisable by its "?iv=".
     */
    private static function decrypt(string $content, NwcConnection $connection): string
    {
        try {
            return str_contains($content, '?iv=')
                ? Nip04::decrypt($content, $connection->secret, $connection->walletPubkey)
                : Nip44::decrypt($content, Nip44::getConversationKey($connection->secret, $connection->walletPubkey));
        } catch (Throwable $exception) {
            throw new PaymentException('Não foi possível abrir a resposta da carteira.', previous: $exception);
        }
    }

    private static function hasTag(object $event, string $name, string $value): bool
    {
        foreach ((array) ($event->tags ?? []) as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name && ($tag[1] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }
}
