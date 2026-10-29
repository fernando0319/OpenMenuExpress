<?php

namespace App\Printing;

use App\Enums\PrintFont;

/**
 * Minimal ESC/POS document builder for thermal receipt printers.
 * Text goes out in code page 850 (accents for Portuguese), which most printers support.
 */
final class EscPos
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private string $bytes;

    private readonly int $columns;

    /**
     * @param  int  $paperColumns  characters per line at the normal font (32 on 58 mm, 48 on 80 mm)
     */
    public function __construct(int $paperColumns = 48, private readonly PrintFont $font = PrintFont::Normal)
    {
        $this->columns = $font === PrintFont::Extra ? intdiv($paperColumns, 2) : $paperColumns;

        // Reset, select code page PC850 (ESC t 2), then the base letter size.
        $this->bytes = self::ESC.'@'.self::ESC.'t'.chr(2).self::GS.'!'.chr($this->baseSize());
    }

    public function columns(): int
    {
        return $this->columns;
    }

    public function align(string $alignment): self
    {
        $this->bytes .= self::ESC.'a'.chr(match ($alignment) {
            'center' => 1,
            'right' => 2,
            default => 0,
        });

        return $this;
    }

    public function bold(bool $on = true): self
    {
        $this->bytes .= self::ESC.'E'.chr($on ? 1 : 0);

        return $this;
    }

    /**
     * Character size: 1 = the printer's base font, 2 = headline (double width and height).
     */
    public function size(int $multiplier): self
    {
        if ($multiplier <= 1) {
            $this->bytes .= self::GS.'!'.chr($this->baseSize());

            return $this;
        }

        // A headline on "extra" (already double) goes one step further.
        $factor = min(7, $multiplier - 1 + ($this->font === PrintFont::Extra ? 1 : 0));
        $this->bytes .= self::GS.'!'.chr(($factor << 4) | $factor);

        return $this;
    }

    /**
     * GS ! value of the base font: high nibble = width factor, low nibble = height factor.
     */
    private function baseSize(): int
    {
        return match ($this->font) {
            PrintFont::Normal => 0x00,
            PrintFont::Large => 0x01,
            PrintFont::Extra => 0x11,
        };
    }

    public function line(string $text = ''): self
    {
        $this->bytes .= self::encode($text)."\n";

        return $this;
    }

    /**
     * Long text broken into lines that fit the paper (at the current size).
     */
    public function wrapped(string $text, string $indent = '', int $sizeMultiplier = 1): self
    {
        $width = max(8, intdiv($this->columns, max(1, $sizeMultiplier)) - mb_strlen($indent));

        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            foreach (explode("\n", wordwrap($paragraph, $width, "\n", true)) as $piece) {
                $this->line($indent.$piece);
            }
        }

        return $this;
    }

    /**
     * Left text and right text on the same line, the right one aligned to the margin.
     */
    public function columnsLine(string $left, string $right): self
    {
        $space = $this->columns - mb_strlen($right) - 1;

        if (mb_strlen($left) > $space) {
            $this->wrapped($left);

            return $this->line(str_pad('', $this->columns - mb_strlen($right)).$right);
        }

        return $this->line($left.str_repeat(' ', $this->columns - mb_strlen($left) - mb_strlen($right)).$right);
    }

    public function separator(string $character = '-'): self
    {
        return $this->line(str_repeat($character, $this->columns));
    }

    public function feed(int $lines = 1): self
    {
        $this->bytes .= self::ESC.'d'.chr(max(0, min(255, $lines)));

        return $this;
    }

    /**
     * Feeds enough paper to clear the cutter and cuts (partial cut).
     */
    public function cut(): self
    {
        $this->bytes .= self::GS.'V'.chr(66).chr(3);

        return $this;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    private static function encode(string $text): string
    {
        // CP850 has ½ and ¼, but not the other fractions used for pizza flavors.
        $text = strtr($text, ['⅓' => '1/3', '⅔' => '2/3', '⅕' => '1/5', '⅙' => '1/6', '⅛' => '1/8']);
        $converted = @iconv('UTF-8', 'CP850//TRANSLIT', $text);

        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }
}
