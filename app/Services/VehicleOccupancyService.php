<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Support\VehicleCategory;

/**
 * Phase 1: the single source of truth for "vehicles inside".
 *
 * Before, the Dashboard added open guest sessions to the registered count
 * (16) while Vehicle Registry and RFID Desk showed registered vehicles only
 * (2). Every page now reads the same numbers from here.
 *
 * Phase 0 (visitor model): only REGISTERED vehicles have an inside/outside
 * state. Vehicles without a tag are counted as IN/OUT movements, never as
 * "inside" (there is no reset for them, so errors would pile up).
 */
class VehicleOccupancyService
{
    /**
     * @return array{registered: int, total: int}
     */
    public function counts(): array
    {
        $registered = Vehicle::query()
            ->where('category', '!=', 'guest')
            ->where('status', 'active')
            ->where('current_state', Vehicle::STATE_INSIDE)
            ->count();

        return [
            'registered' => $registered,
            'total' => $registered,
        ];
    }

    /**
     * Phase 7 (visitor model): registered vehicles inside, per category.
     *
     * @return array<string, array{label: string, inside: int}>
     */
    public function insideByCategory(): array
    {
        $inside = Vehicle::query()
            ->where('status', 'active')
            ->where('current_state', Vehicle::STATE_INSIDE)
            ->pluck('category')
            ->countBy(fn (?string $category): string => VehicleCategory::normalize($category));

        return collect(VehicleCategory::REGISTRY)
            ->mapWithKeys(fn (string $category): array => [$category => [
                'label' => VehicleCategory::LABELS[$category],
                'inside' => (int) ($inside[$category] ?? 0),
            ]])
            ->all();
    }
}
