<?php

namespace App\Http\Controllers\StoreSettings;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Services\Scheduling\StoreSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly opening hours and the rules for scheduled orders.
 */
class HoursController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(): Response
    {
        $store = $this->currentStore();

        return Inertia::render('store-settings/Hours', [
            'week' => StoreSchedule::for($store)->weekly(),
            'scheduling' => [
                'accepts_scheduling' => $store->accepts_scheduling,
                'scheduling_lead_minutes' => $store->scheduling_lead_minutes,
                'scheduling_days_ahead' => $store->scheduling_days_ahead,
                'scheduling_slot_minutes' => $store->scheduling_slot_minutes,
                'scheduling_slot_capacity' => $store->scheduling_slot_capacity,
            ],
            'timezone' => $store->timezone,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shifts' => ['array', 'max:35'],
            'shifts.*.weekday' => ['required', 'integer', 'between:0,6'],
            'shifts.*.opens' => ['required', 'date_format:H:i'],
            'shifts.*.closes' => ['required', 'date_format:H:i'],
            'accepts_scheduling' => ['boolean'],
            'scheduling_lead_minutes' => ['required', 'integer', 'min:0', 'max:2880'],
            'scheduling_days_ahead' => ['required', 'integer', 'min:0', 'max:14'],
            'scheduling_slot_minutes' => ['required', 'integer', 'in:15,20,30,45,60'],
            'scheduling_slot_capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [
            'shifts.*.opens.date_format' => 'Use o formato 18:00.',
            'shifts.*.closes.date_format' => 'Use o formato 23:00.',
        ]);

        $store = $this->currentStore();

        DB::transaction(function () use ($store, $data): void {
            $store->openingHours()->delete();

            foreach ($data['shifts'] ?? [] as $shift) {
                $store->openingHours()->create([
                    'weekday' => $shift['weekday'],
                    'opens_at' => $shift['opens'],
                    'closes_at' => $shift['closes'],
                ]);
            }

            $store->update([
                'accepts_scheduling' => $data['accepts_scheduling'] ?? false,
                'scheduling_lead_minutes' => $data['scheduling_lead_minutes'],
                'scheduling_days_ahead' => $data['scheduling_days_ahead'],
                'scheduling_slot_minutes' => $data['scheduling_slot_minutes'],
                'scheduling_slot_capacity' => $data['scheduling_slot_capacity'] ?? null,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Horários salvos.']);

        return to_route('store-settings.hours.edit');
    }
}
