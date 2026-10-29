<?php

namespace Tests\Unit\Payments;

use App\Payments\PixBrCode;
use PHPUnit\Framework\TestCase;

class PixBrCodeTest extends TestCase
{
    public function test_it_writes_the_static_pix_code_of_the_banco_central_manual(): void
    {
        // The example of the "Manual de Padrões para Iniciação do Pix", with its checksum 1D3D.
        $this->assertSame(
            '00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913FULANO DE TAL6008BRASILIA62070503***6304'.$this->crcOf('00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913FULANO DE TAL6008BRASILIA62070503***6304'),
            PixBrCode::payload('123e4567-e12b-12d1-a456-426655440000', 'Fulano de Tal', 'Brasília', 0, ''),
        );
        $this->assertSame('1D3D', $this->crcOf('00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***6304'));
    }

    public function test_amount_name_city_and_identifier_follow_the_limits_of_the_standard(): void
    {
        $code = PixBrCode::payload('+5542999990000', 'Lanchonete São João da Esquina do Centro', 'São João do Triunfo', 1990, 'MX-42 pedido');

        $this->assertStringContainsString('0114+5542999990000', $code);
        $this->assertStringContainsString('540519.90', $code);
        $this->assertStringContainsString('5925LANCHONETE SAO JOAO DA ES', $code);
        $this->assertStringContainsString('6015SAO JOAO DO TRI', $code);
        $this->assertStringContainsString('62140510MX42pedido', $code);
        $this->assertSame($this->crcOf(substr($code, 0, -4)), substr($code, -4));
    }

    /**
     * CRC16-CCITT written apart from the class, so the test does not trust its own subject.
     */
    private function crcOf(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }
}
