<?php

namespace Tests\Unit\Payments;

use App\Payments\ClientCertificate;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Self-signed test certificates in tests/fixtures/certificates (password "Senha123").
 */
class ClientCertificateTest extends TestCase
{
    private const PASSWORD = 'Senha123';

    public function test_a_modern_pfx_is_kept_as_it_is(): void
    {
        $pfx = $this->fixture('modern.pfx');

        $this->assertSame($pfx, ClientCertificate::toPkcs12($pfx, null, self::PASSWORD));
    }

    public function test_certificate_and_key_files_become_a_pfx(): void
    {
        foreach ([
            'separate .pem and .key' => [$this->fixture('cert.pem'), $this->fixture('key.pem'), ''],
            'binary .cer and .key' => [$this->fixture('cert.cer'), $this->fixture('key.pem'), ''],
            'both in one .pem' => [$this->fixture('bundle.pem'), null, ''],
            'key with a password' => [$this->fixture('cert.pem'), $this->fixture('key-encrypted.pem'), self::PASSWORD],
        ] as $case => [$certificate, $key, $password]) {
            $this->assertOpens(ClientCertificate::toPkcs12($certificate, $key, $password), $password, $case);
        }
    }

    public function test_mistakes_are_explained(): void
    {
        $this->assertFailsWith('confira a senha', $this->fixture('modern.pfx'), null, 'errada');
        $this->assertFailsWith('Envie também a chave privada', $this->fixture('cert.pem'), null, '');
        $this->assertFailsWith('não pertence a este certificado', $this->fixture('cert.pem'), $this->fixture('other-key.pem'), '');
        $this->assertFailsWith('confira a senha', $this->fixture('cert.pem'), $this->fixture('key-encrypted.pem'), 'errada');
        $this->assertFailsWith('não reconhecido', 'isto não é um certificado', null, '');
    }

    public function test_a_pfx_with_old_ciphers_is_repacked(): void
    {
        if (! Process::run(['openssl', 'version'])->successful()) {
            $this->markTestSkipped('The openssl tool is not available here (it is on the server).');
        }

        $pfx = $this->fixture('legacy.pfx');
        $parsed = [];

        $repacked = ClientCertificate::toPkcs12($pfx, null, self::PASSWORD);

        $this->assertNotSame($pfx, $repacked);
        $this->assertTrue(openssl_pkcs12_read($repacked, $parsed, self::PASSWORD));
    }

    private function assertOpens(string $pkcs12, string $password, string $case): void
    {
        $parsed = [];

        $this->assertTrue(openssl_pkcs12_read($pkcs12, $parsed, $password), $case);
        $this->assertStringContainsString('Menu Express teste', (string) openssl_x509_parse($parsed['cert'])['name'], $case);
    }

    private function assertFailsWith(string $message, string $certificate, ?string $key, string $password): void
    {
        try {
            ClientCertificate::toPkcs12($certificate, $key, $password);
            $this->fail("Expected: {$message}");
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/fixtures/certificates/{$name}"));
    }
}
