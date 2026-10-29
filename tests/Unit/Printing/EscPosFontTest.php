<?php

namespace Tests\Unit\Printing;

use App\Enums\PrintFont;
use App\Printing\EscPos;
use PHPUnit\Framework\TestCase;

class EscPosFontTest extends TestCase
{
    public function test_the_base_font_is_set_after_the_reset_and_restored_after_headlines(): void
    {
        $large = (new EscPos(48, PrintFont::Large))->size(2)->line('PEDIDO 1')->size(1)->line('item')->bytes();

        $this->assertStringStartsWith("\x1B@\x1Bt\x02\x1D!\x01", $large);
        $this->assertStringContainsString("\x1D!\x11PEDIDO 1\n\x1D!\x01item", $large);
        $this->assertSame(48, (new EscPos(48, PrintFont::Large))->columns());
    }

    public function test_extra_large_halves_the_characters_per_line(): void
    {
        $doc = new EscPos(48, PrintFont::Extra);

        $this->assertSame(24, $doc->columns());
        $this->assertStringContainsString("\x1D!\x11", $doc->bytes());
        $this->assertStringContainsString("\x1D!\x22", $doc->size(2)->bytes());
        $this->assertSame(str_repeat('-', 24)."\n", substr((new EscPos(48, PrintFont::Extra))->separator()->bytes(), -25));
    }

    public function test_normal_keeps_the_printer_default(): void
    {
        $this->assertStringStartsWith("\x1B@\x1Bt\x02\x1D!\x00", (new EscPos(32))->bytes());
    }
}
