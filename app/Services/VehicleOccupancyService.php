<?php

namespace App\Services;

use App\Models\GuestVisit;
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
     * Phase 3: guests inside = guest pass visits that are still open
     * (active or overstay). CCTV guest sessions no longer count.
     */
    protected function guestsInside(): int
    {
        return GuestVisit::query()->open()->count();
    }
}
