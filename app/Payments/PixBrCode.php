<?php

namespace App\Payments;

use Illuminate\Support\Str;

/**
 * The "Pix copia e cola" code (BR Code) of a static Pix, as the Banco Central specifies it
 * (EMV QR Code, Manual de Padrões para Iniciação do Pix). Any bank app reads it, and the money
 * goes straight to the key: no bank API, certificate or contract involved.
 */
final class PixBrCode
{
    /**
     * @param  string  $key  Pix key already normalized (digits, +55…, e-mail or random key)
     * @param  string  $txid  Identifier shown in the merchant's statement by most banks (letters and digits, up to 25)
     */
    public static function payload(string $key, string $merchantName, string $merchantCity, int $amountCents, string $txid): string
    {
        $account = self::field('00', 'br.gov.bcb.pix').self::field('01', $key);

        $payload = self::field('00', '01')
            .self::field('26', $account)
            .self::field('52', '0000')
            .self::field('53', '986')
            .($amountCents > 0 ? self::field('54', number_format($amountCents / 100, 2, '.', '')) : '')
            .self::field('58', 'BR')
            .self::field('59', self::text($merchantName, 25, 'LOJA'))
            .self::field('60', self::text($merchantCity, 15, 'BRASIL'))
            .self::field('62', self::field('05', self::txid($txid)))
            .'6304';

        return $payload.self::crc16($payload);
    }

    /**
     * Letters and digits only, up to 25 characters; "***" means "no identifier".
     */
    public static function txid(string $txid): string
    {
        $clean = substr(preg_replace('/[^A-Za-z0-9]/', '', $txid) ?? '', 0, 25);

        return $clean === '' ? '***' : $clean;
    }

    private static function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * Names and cities travel without accents, in capitals, within the size the standard allows.
     */
    private static function text(string $value, int $max, string $fallback): string
    {
        $ascii = strtoupper(trim(preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($value)) ?? ''));
        $ascii = trim(substr(preg_replace('/\s+/', ' ', $ascii) ?? '', 0, $max));

        return $ascii === '' ? $fallback : $ascii;
    }

    /**
     * CRC16-CCITT (polynomial 0x1021, start 0xFFFF), as four upper-case hex digits.
     */
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
