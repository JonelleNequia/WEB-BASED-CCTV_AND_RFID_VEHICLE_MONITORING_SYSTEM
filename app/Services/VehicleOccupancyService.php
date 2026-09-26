<?php

namespace App\Services;

use App\Models\ActiveSession;
use App\Models\Vehicle;

/**
 * Phase 1: the single source of truth for "vehicles inside".
 *
 * Before, the Dashboard added open guest sessions to the registered count
 * (16) while Vehicle Registry and RFID Desk showed registered vehicles only
 * (2). Every page now reads the same numbers from here.
 */
class VehicleOccupancyService
{
    /**
     * @return array{registered: int, guests: int, total: int}
     */
    public function counts(): array
    {
        $registered = Vehicle::query()
            ->where('category', '!=', 'guest')
            ->where('status', 'active')
            ->where('current_state', Vehicle::STATE_INSIDE)
            ->count();

        $guests = $this->guestsInside();

        return [
            'registered' => $registered,
            'guests' => $guests,
            'total' => $registered + $guests,
        ];
    }

    /**
     * Guest vehicles with an open session.
     *
     * Phase 2 adds a cleanup command for stale CCTV guest sessions, and Phase 3
     * switches this to active guest pass visits.
     */
    protected function guestsInside(): int
    {
        return ActiveSession::query()
            ->where('status', 'open')
            ->whereHas('entryEvent', function ($query): void {
                $query->where(function ($guestQuery): void {
                    $guestQuery->where('vehicle_category', 'guest')
                        ->orWhereIn('event_origin', ['guest_cctv', 'guest_manual']);
                });
            })
            ->count();
    }
}
