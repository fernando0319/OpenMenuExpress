<?php

namespace App\Payments;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * A bank's client certificate (mutual TLS), in whatever form the store has it:
 * a .pfx/.p12 file with its password, or the certificate (.pem/.crt/.cer, text or binary)
 * with the private key (.key/.pem, maybe protected by a password), or both in one .pem.
 * Everything is turned into a single PKCS#12, the form the gateways send.
 */
final class ClientCertificate
{
    /**
     * @return string PKCS#12 contents, protected by $password.
     *
     * @throws \InvalidArgumentException with a message for the store.
     */
    public static function toPkcs12(string $certificate, ?string $privateKey, string $password): string
    {
        $parsed = [];

        if (openssl_pkcs12_read($certificate, $parsed, $password)) {
            return $certificate;
        }

        // Many A1 certificates still use old ciphers (RC2/3DES) that OpenSSL 3 refuses by
        // default: unpack them with the openssl tool in legacy mode and pack them anew.
        if (self::looksLikePkcs12($certificate)) {
            $pem = self::unpackLegacy($certificate, $password);

            if ($pem !== null) {
                return self::toPkcs12($pem, null, $password);
            }
        }

        $certificates = self::certificates($certificate);

        if ($certificates === []) {
            throw new \InvalidArgumentException(self::looksLikePkcs12($certificate)
                ? 'Não foi possível abrir o certificado .pfx: confira a senha.'
                : 'Arquivo de certificado não reconhecido. Envie o .pfx ou o certificado (.pem, .crt ou .cer).');
        }

        // The key may come in its own file or inside the same .pem as the certificate.
        $keyText = $privateKey ?? $certificate;
        $key = openssl_pkey_get_private($keyText, str_contains($keyText, 'ENCRYPTED') ? $password : null);

        if ($key === false) {
            throw new \InvalidArgumentException(match (true) {
                $privateKey === null && ! str_contains($certificate, 'PRIVATE KEY') => 'Envie também a chave privada (.key) do certificado.',
                str_contains($keyText, 'ENCRYPTED') => 'Não foi possível abrir a chave privada: confira a senha.',
                default => 'Chave privada não reconhecida. Envie o arquivo .key (ou .pem) do certificado.',
            });
        }

        $leaf = array_shift($certificates);

        if (! openssl_x509_check_private_key($leaf, $key)) {
            throw new \InvalidArgumentException('A chave privada não pertence a este certificado.');
        }

        $bundle = '';

        if (! openssl_pkcs12_export($leaf, $bundle, $key, $password, $certificates === [] ? [] : ['extracerts' => $certificates])) {
            throw new \InvalidArgumentException('Não foi possível preparar o certificado. Tente enviar o arquivo .pfx.');
        }

        return $bundle;
    }

    /**
     * Certificates in a PEM text (the first one is the store's), or the single one of a DER file.
     *
     * @return list<string> PEM blocks
     */
    private static function certificates(string $contents): array
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $matches) > 0) {
            return array_values(array_filter($matches[0], fn (string $pem) => @openssl_x509_read($pem) !== false));
        }

        // Binary (DER) .cer/.crt.
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($contents), 64, "\n")."-----END CERTIFICATE-----\n";

        return @openssl_x509_read($pem) !== false ? [$pem] : [];
    }

    /**
     * Certificate and key as PEM text from a legacy .pfx, or null when it cannot be opened.
     */
    private static function unpackLegacy(string $pkcs12, string $password): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'pfx');

        if ($path === false) {
            return null;
        }

        try {
            file_put_contents($path, $pkcs12);
            chmod($path, 0600);

            // The password goes through the environment, never on the command line.
            $result = Process::env(['MX_PFX_PASS' => $password])
                ->timeout(15)
                ->run([self::opensslBinary(), 'pkcs12', '-in', $path, '-nodes', '-legacy', '-passin', 'env:MX_PFX_PASS']);

            return $result->successful() && str_contains($result->output(), 'PRIVATE KEY') ? $result->output() : null;
        } finally {
            @unlink($path);
        }
    }

    /**
     * PHP-FPM usually runs with an empty PATH, so the usual places are tried as well.
     */
    private static function opensslBinary(): string
    {
        return (new ExecutableFinder)->find('openssl', 'openssl', ['/usr/bin', '/usr/local/bin', '/bin']);
    }

    private static function looksLikePkcs12(string $contents): bool
    {
        // PKCS#12 is binary DER that starts with a SEQUENCE and is not a plain certificate.
        return $contents !== '' && $contents[0] === "\x30" && ! str_contains($contents, '-----BEGIN');
    }
}
