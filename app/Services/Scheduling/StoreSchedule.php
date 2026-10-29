<?php

namespace App\Services\Scheduling;

use App\Enums\OrderStatus;
use App\Models\OpeningHour;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Answers "is the store open?", "when does it open?" and "which delivery times can be
 * booked?" from its weekly shifts, in the store's timezone. Results are returned in UTC.
 */
final class StoreSchedule
{
    /**
     * @param  Collection<int, OpeningHour>  $shifts
     */
    private function __construct(
        private readonly Store $store,
        private readonly Collection $shifts,
    ) {}

    public static function for(Store $store): self
    {
        return new self($store, $store->openingHours()->orderBy('weekday')->orderBy('opens_at')->get());
    }

    public function hasHours(): bool
    {
        return $this->shifts->isNotEmpty();
    }

    /**
     * Open for orders right now: not paused and inside a shift (no shifts configured = always open).
     */
    public function isOpenAt(CarbonImmutable $moment): bool
    {
        if ($this->store->is_paused) {
            return false;
        }

        if (! $this->hasHours()) {
            return true;
        }

        $local = $moment->setTimezone($this->store->timezone);
        $weekday = $local->dayOfWeek;
        $yesterday = ($weekday + 6) % 7;
        $minute = $local->hour * 60 + $local->minute;

        foreach ($this->shifts as $shift) {
            $opens = $shift->opensAtMinutes();
            $closes = $shift->closesAtMinutes();

            if (! $shift->crossesMidnight()) {
                if ($shift->weekday === $weekday && $minute >= $opens && $minute < $closes) {
                    return true;
                }

                continue;
            }

            if ($shift->weekday === $weekday && $minute >= $opens) {
                return true;
            }

            if ($shift->weekday === $yesterday && $minute < $closes) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start of the next shift after the given moment, within a week.
     */
    public function nextOpening(CarbonImmutable $from): ?CarbonImmutable
    {
        if (! $this->hasHours() || $this->store->is_paused) {
            return null;
        }

        $localDay = $from->setTimezone($this->store->timezone)->startOfDay();
        $candidates = [];

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $localDay->addDays($offset);

            foreach ($this->shiftsOn($day->dayOfWeek) as $shift) {
                $start = $day->addMinutes($shift->opensAtMinutes());

                if ($start->greaterThan($from)) {
                    $candidates[] = $start;
                }
            }

            if ($candidates !== []) {
                break;
            }
        }

        return collect($candidates)->sortBy(fn (CarbonImmutable $start) => $start->getTimestamp())->first()?->utc();
    }

    /**
     * Bookable times from now: on the slot grid of each shift, at least the lead time ahead,
     * up to the configured number of days, skipping full slots. Ordered, in UTC.
     *
     * @return list<CarbonImmutable>
     */
    public function availableSlots(CarbonImmutable $now): array
    {
        if (! $this->store->accepts_scheduling || ! $this->hasHours() || $this->store->is_paused) {
            return [];
        }

        $step = max(5, $this->store->scheduling_slot_minutes);
        $earliest = $now->addMinutes($this->store->scheduling_lead_minutes);
        $today = $now->setTimezone($this->store->timezone)->startOfDay();
        $slots = [];

        for ($offset = 0; $offset <= $this->store->scheduling_days_ahead; $offset++) {
            $day = $today->addDays($offset);

            foreach ($this->shiftsOn($day->dayOfWeek) as $shift) {
                $start = $day->addMinutes($shift->opensAtMinutes());
                $end = $day->addMinutes($shift->closesAtMinutes() + ($shift->crossesMidnight() ? 24 * 60 : 0));

                for ($slot = $start; $slot->lessThan($end); $slot = $slot->addMinutes($step)) {
                    if ($slot->greaterThanOrEqualTo($earliest)) {
                        $slots[$slot->getTimestamp()] = $slot->utc();
                    }
                }
            }
        }

        ksort($slots);

        $booked = $this->bookedCounts($slots);
        $capacity = $this->store->scheduling_slot_capacity;

        return array_values(array_filter(
            $slots,
            fn (CarbonImmutable $slot) => $capacity === null || $capacity <= 0 || ($booked[$slot->getTimestamp()] ?? 0) < $capacity,
        ));
    }

    public function isAvailableSlot(CarbonImmutable $slot, CarbonImmutable $now): bool
    {
        foreach ($this->availableSlots($now) as $available) {
            if ($available->equalTo($slot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{weekday: int, shifts: list<array{opens: string, closes: string}>}>
     */
    public function weekly(): array
    {
        $week = [];

        for ($weekday = 0; $weekday < 7; $weekday++) {
            $week[] = [
                'weekday' => $weekday,
                'shifts' => array_values($this->shiftsOn($weekday)
                    ->map(fn (OpeningHour $shift) => [
                        'opens' => substr($shift->opens_at, 0, 5),
                        'closes' => substr($shift->closes_at, 0, 5),
                    ])
                    ->all()),
            ];
        }

        return $week;
    }

    /**
     * @return Collection<int, OpeningHour>
     */
    private function shiftsOn(int $weekday): Collection
    {
        return $this->shifts->filter(fn (OpeningHour $shift) => $shift->weekday === $weekday)->values();
    }

    /**
     * Active orders already booked per slot, keyed by timestamp, in one query.
     *
     * @param  array<int, CarbonImmutable>  $slots
     * @return array<int, int>
     */
    private function bookedCounts(array $slots): array
    {
        $capacity = $this->store->scheduling_slot_capacity;

        if ($slots === [] || $capacity === null || $capacity <= 0) {
            return [];
        }

        $counts = [];

        $this->store->orders()
            ->whereBetween('scheduled_for', [reset($slots), end($slots)])
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Refunded])
            ->pluck('scheduled_for')
            ->each(function (mixed $value) use (&$counts): void {
                $timestamp = CarbonImmutable::parse((string) $value)->getTimestamp();
                $counts[$timestamp] = ($counts[$timestamp] ?? 0) + 1;
            });

        return $counts;
    }
}
