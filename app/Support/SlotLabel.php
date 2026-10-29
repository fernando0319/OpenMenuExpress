<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Human labels for dates in the store's timezone: "Hoje", "Amanhã", "sábado, 20/10".
 */
final class SlotLabel
{
    private const WEEKDAYS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    public static function day(CarbonImmutable $moment, string $timezone, CarbonImmutable $now): string
    {
        $local = $moment->setTimezone($timezone)->startOfDay();
        $today = $now->setTimezone($timezone)->startOfDay();
        $days = (int) $today->diffInDays($local);

        return match (true) {
            $local->equalTo($today) => 'Hoje',
            $days === 1 && $local->greaterThan($today) => 'Amanhã',
            default => self::WEEKDAYS[$local->dayOfWeek].', '.$local->format('d/m'),
        };
    }

    /**
     * "Hoje às 18:00", "Amanhã às 11:00", "sábado, 20/10 às 18:00" — or null.
     */
    public static function describe(?CarbonImmutable $moment, string $timezone, CarbonImmutable $now): ?string
    {
        if ($moment === null) {
            return null;
        }

        return self::day($moment, $timezone, $now).' às '.$moment->setTimezone($timezone)->format('H:i');
    }
}
