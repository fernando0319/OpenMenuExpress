<?php

namespace App\Enums;

/**
 * Letter size on the tickets of a printer.
 */
enum PrintFont: string
{
    /** The printer's standard font. */
    case Normal = 'normal';

    /** Double height: much easier to read, same characters per line. */
    case Large = 'large';

    /** Double height and width: half the characters per line. */
    case Extra = 'extra';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Large => 'Grande',
            self::Extra => 'Extra grande',
        };
    }
}
