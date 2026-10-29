<?php

namespace App\Messaging\Evolution;

use App\Messaging\MessagingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin client for the Evolution API v2 endpoints this app uses.
 * The server may be shared with other systems, so every instance operation is
 * restricted to names that start with our prefix.
 *
 * @see https://doc.evolution-api.com
 */
final class EvolutionClient
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly string $instancePrefix = 'mx-',
    ) {
        $baseUrl = trim($baseUrl);

        if ($baseUrl !== '' && ! preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function instanceNameFor(string $storeSlug): string
    {
        return $this->instancePrefix.$storeSlug;
    }

    /**
     * Creates the instance if needed and asks for a QR code (or a pairing code when a number is given).
     */
    public function connect(string $instance, ?string $phone = null): ConnectionPrompt
    {
        $this->assertManaged($instance);

        if ($this->state($instance) === ConnectionState::NotFound) {
            $created = $this->send(fn (PendingRequest $http) => $http->post('/instance/create', array_filter([
                'instanceName' => $instance,
                'integration' => 'WHATSAPP-BAILEYS',
                'qrcode' => true,
                'number' => $phone,
            ])));

            $prompt = ConnectionPrompt::fromArray($this->arrayAt($created->json(), 'qrcode'));

            if ($prompt->hasSomething() && $phone === null) {
                return $prompt;
            }
        }

        $query = $phone !== null ? '?number='.rawurlencode($phone) : '';
        $response = $this->send(fn (PendingRequest $http) => $http->get('/instance/connect/'.rawurlencode($instance).$query));

        return ConnectionPrompt::fromArray($this->arrayAt($response->json(), null));
    }

    public function state(string $instance): ConnectionState
    {
        $this->assertManaged($instance);

        $response = $this->send(
            fn (PendingRequest $http) => $http->get('/instance/connectionState/'.rawurlencode($instance)),
            allowNotFound: true,
        );

        if ($response->status() === 404) {
            return ConnectionState::NotFound;
        }

        $state = $this->arrayAt($response->json(), 'instance')['state'] ?? null;

        return ConnectionState::tryFrom(is_string($state) ? $state : '') ?? ConnectionState::Close;
    }

    /**
     * Phone number (digits) and profile name of the connected WhatsApp, when available.
     *
     * @return array{number: string|null, profileName: string|null}
     */
    public function profile(string $instance): array
    {
        $this->assertManaged($instance);

        $response = $this->send(
            fn (PendingRequest $http) => $http->get('/instance/fetchInstances', ['instanceName' => $instance]),
            allowNotFound: true,
        );

        $list = $response->json();
        $first = is_array($list) && isset($list[0]) && is_array($list[0]) ? $list[0] : [];
        $owner = $first['ownerJid'] ?? null;
        $profileName = $first['profileName'] ?? null;

        return [
            'number' => is_string($owner) ? explode('@', $owner)[0] : null,
            'profileName' => is_string($profileName) ? $profileName : null,
        ];
    }

    public function logout(string $instance): void
    {
        $this->assertManaged($instance);

        $this->send(
            fn (PendingRequest $http) => $http->delete('/instance/logout/'.rawurlencode($instance)),
            allowNotFound: true,
        );
    }

    public function sendText(string $instance, string $phone, string $text): void
    {
        $this->assertManaged($instance);

        $this->send(fn (PendingRequest $http) => $http->post('/message/sendText/'.rawurlencode($instance), [
            'number' => $phone,
            'text' => $text,
        ]));
    }

    private function assertManaged(string $instance): void
    {
        if (! str_starts_with($instance, $this->instancePrefix) || strlen($instance) <= strlen($this->instancePrefix)) {
            throw new InvalidArgumentException("Instância \"{$instance}\" não pertence a este sistema.");
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request, bool $allowNotFound = false): Response
    {
        if (! $this->isConfigured()) {
            throw new MessagingException('Evolution API não configurada (EVOLUTION_API_URL / EVOLUTION_API_KEY).');
        }

        $http = Http::baseUrl($this->baseUrl)
            ->withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()
            ->timeout(20);

        try {
            $response = $request($http);
        } catch (ConnectionException $exception) {
            throw new MessagingException('Servidor do WhatsApp indisponível: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed() && ! ($allowNotFound && $response->status() === 404)) {
            throw new MessagingException("Evolution respondeu HTTP {$response->status()}: ".mb_substr($response->body(), 0, 200));
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    private function arrayAt(mixed $json, ?string $key): array
    {
        $value = $key === null ? $json : (is_array($json) ? ($json[$key] ?? null) : null);

        return is_array($value) ? $value : [];
    }
}
