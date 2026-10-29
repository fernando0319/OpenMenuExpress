<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A date range in the store's timezone, with its UTC bounds for queries
 * and the previous range of the same length for comparisons.
 */
final readonly class ReportPeriod
{
    public const PRESETS = ['today', 'yesterday', '7d', '30d', 'month', 'last_month', 'custom'];

    private function __construct(
        public string $preset,
        /** First local day (00:00). */
        public CarbonImmutable $startLocal,
        /** Last local day (23:59:59). */
        public CarbonImmutable $endLocal,
        public string $timezone,
    ) {}

    public static function from(string $preset, ?string $from, ?string $to, string $timezone, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();

        [$start, $end] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom' => self::customRange($from, $to, $timezone),
            default => throw ValidationException::withMessages(['period' => 'Período inválido.']),
        };

        return new self($preset, $start->startOfDay(), $end->endOfDay(), $timezone);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function customRange(?string $from, ?string $to, string $timezone): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $from, $timezone);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $to, $timezone);
        } catch (\Throwable) {
            $start = $end = null;
        }

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable || $end->lessThan($start)) {
            throw ValidationException::withMessages(['period' => 'Escolha uma data inicial e uma final válidas.']);
        }

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['period' => 'O período pode ter no máximo um ano.']);
        }

        return [$start, $end];
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->startLocal->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->endLocal->utc();
    }

    public function days(): int
    {
        return (int) $this->startLocal->diffInDays($this->endLocal->startOfDay()) + 1;
    }

    /**
     * The same number of days right before this period.
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self(
            $this->preset,
            $this->startLocal->subDays($days),
            $this->startLocal->subDay()->endOfDay(),
            $this->timezone,
        );
    }

    /**
     * Every local date in the period, "Y-m-d".
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        for ($day = $this->startLocal; $day->lessThanOrEqualTo($this->endLocal); $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    public function label(): string
    {
        return $this->days() === 1
            ? $this->startLocal->format('d/m/Y')
            : $this->startLocal->format('d/m/Y').' a '.$this->endLocal->format('d/m/Y');
    }
}
