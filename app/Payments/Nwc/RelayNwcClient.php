<?php

namespace App\Payments\Nwc;

use App\Payments\PaymentException;
use Illuminate\Support\Str;
use Throwable;
use WebSocket\Client;

/**
 * Nostr Wallet Connect over the wallet's relays: subscribe to the answer first, then publish the
 * request, and read until the answer arrives or the time runs out. Each relay of the connection
 * is tried in turn.
 */
final class RelayNwcClient implements NwcClient
{
    public function info(NwcConnection $connection): array
    {
        return $this->eachRelay($connection, function (Client $client) use ($connection): array {
            $subscription = Str::random(16);
            $client->text((string) json_encode(['REQ', $subscription, ['kinds' => [NwcCodec::INFO_KIND], 'authors' => [$connection->walletPubkey], 'limit' => 1]]));

            $event = $this->await($client, $subscription, stopAtEnd: true);

            if ($event === null) {
                throw new RelayUnavailable('A carteira não publicou o que sabe fazer neste relay.');
            }

            return NwcCodec::info($connection, $event);
        });
    }

    public function request(NwcConnection $connection, string $method, array $params, string $encryption): array
    {
        $request = NwcCodec::request($connection, $method, $params, $encryption);

        return $this->eachRelay($connection, function (Client $client) use ($connection, $request): array {
            $subscription = Str::random(16);
            $client->text((string) json_encode(['REQ', $subscription, [
                'kinds' => [NwcCodec::RESPONSE_KIND],
                'authors' => [$connection->walletPubkey],
                '#e' => [$request->getId()],
            ]]));
            $client->text((string) json_encode(['EVENT', $request->toArray()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $event = $this->await($client, $subscription, stopAtEnd: false, requestId: $request->getId());

            if ($event === null) {
                throw new RelayUnavailable('A carteira não respondeu a tempo. Confira se ela está online.');
            }

            return NwcCodec::response($connection, $event, $request->getId());
        });
    }

    /**
     * The first event of the subscription, or null when the relay ends it (EOSE, with $stopAtEnd)
     * or the time runs out. A relay refusing the request ("OK", false) is an error.
     */
    private function await(Client $client, string $subscription, bool $stopAtEnd, ?string $requestId = null): ?object
    {
        $deadline = microtime(true) + (int) config('payments.nwc.timeout');

        while (microtime(true) < $deadline) {
            try {
                $message = json_decode($client->receive()->getContent());
            } catch (Throwable) {
                return null; // read timed out or the relay closed: nothing came
            }

            if (! is_array($message)) {
                continue;
            }

            $type = $message[0] ?? null;

            if ($type === 'EVENT' && ($message[1] ?? null) === $subscription && is_object($message[2] ?? null)) {
                return $message[2];
            }

            if ($type === 'EOSE' && ($message[1] ?? null) === $subscription && $stopAtEnd) {
                return null;
            }

            if ($type === 'OK' && $requestId !== null && ($message[1] ?? null) === $requestId && ($message[2] ?? null) === false) {
                throw new PaymentException('O relay recusou o pedido: '.mb_substr((string) ($message[3] ?? ''), 0, 120));
            }
        }

        return null;
    }

    /**
     * Runs $talk on each relay until one brings the wallet's answer. The same signed request may
     * go to the next relay (wallets handle an event once), but a refusal by the wallet is final.
     *
     * @template T
     *
     * @param  \Closure(Client): T  $talk
     * @return T
     *
     * @throws PaymentException
     */
    private function eachRelay(NwcConnection $connection, \Closure $talk): mixed
    {
        $error = 'Nenhum relay da carteira respondeu.';

        foreach ($connection->relays as $relay) {
            $client = null;

            try {
                $client = (new Client($relay))->setTimeout((int) config('payments.nwc.timeout'));
                $client->connect();

                return $talk($client);
            } catch (PaymentException $exception) {
                throw $exception;
            } catch (RelayUnavailable $exception) {
                $error = $exception->getMessage();
            } catch (Throwable) {
                $error = 'Não foi possível falar com o relay '.parse_url($relay, PHP_URL_HOST).'.';
            } finally {
                try {
                    $client?->disconnect();
                } catch (Throwable) {
                    // Already gone.
                }
            }
        }

        throw new PaymentException($error);
    }
}
