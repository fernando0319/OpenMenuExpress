<?php

namespace App\Payments;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * The provider refused or could not be reached. The message is safe to show to the merchant.
 */
final class PaymentException extends RuntimeException
{
    public static function fromResponse(string $provider, Response $response): self
    {
        $body = $response->json();
        $detail = is_array($body)
            ? ($body['message'] ?? $body['detail'] ?? $body['error_description'] ?? $body['error'] ?? $body['title'] ?? null)
            : null;

        return new self(trim("{$provider} respondeu {$response->status()}".(is_string($detail) ? ": {$detail}" : '.')));
    }
}
