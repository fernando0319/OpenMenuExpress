<?php

namespace App\Messaging\Evolution;

/**
 * What the person needs to link a phone: a QR code image and/or a pairing code.
 */
final readonly class ConnectionPrompt
{
    public function __construct(
        public ?string $qrCodeImage,
        public ?string $pairingCode,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $base64 = $data['base64'] ?? null;
        $pairingCode = $data['pairingCode'] ?? null;

        if (is_string($base64) && $base64 !== '' && ! str_starts_with($base64, 'data:')) {
            $base64 = 'data:image/png;base64,'.$base64;
        }

        return new self(
            is_string($base64) && $base64 !== '' ? $base64 : null,
            is_string($pairingCode) && $pairingCode !== '' ? $pairingCode : null,
        );
    }

    public function hasSomething(): bool
    {
        return $this->qrCodeImage !== null || $this->pairingCode !== null;
    }
}
