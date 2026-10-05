<?php

namespace App\Support;

/**
 * A2 (detection): the three vehicle types shown to users. Older or
 * registry labels (Truck, Bus, Pickup, SUV...) are read as one of them.
 */
final class VehicleType
{
    public const CAR = 'Car';

    public const MOTORCYCLE = 'Motorcycle';

    public const TRUCK_BUS = 'Truck/Bus';

    public const TYPES = [self::CAR, self::MOTORCYCLE, self::TRUCK_BUS];

    /** Car: sedan, hatchback, SUV, AUV, pickup, van. Motorcycle: motor, e-bike, tricycle. Truck/Bus: truck, bus, jeepney. */
    public const HINTS = [
        self::CAR => 'Sedan, hatchback, SUV, AUV, pickup, van',
        self::MOTORCYCLE => 'Motorcycle, e-bike, tricycle',
        self::TRUCK_BUS => 'Truck, bus, jeepney',
    ];

    public static function category(?string $label): ?string
    {
        $label = strtolower(trim(str_replace(['_', '-'], ' ', (string) $label)));

        return match (true) {
            $label === '' => null,
            in_array($label, ['car', 'sedan', 'hatchback', 'suv', 'auv', 'mpv', 'van', 'pickup', 'pickup truck', 'car van'], true) => self::CAR,
            in_array($label, ['motorcycle', 'motorbike', 'motor', 'scooter', 'ebike', 'e bike', 'tricycle', 'electric scooter'], true) => self::MOTORCYCLE,
            in_array($label, ['truck', 'bus', 'jeepney', 'jeep', 'truck/bus', 'truck / bus'], true) => self::TRUCK_BUS,
            default => null,
        };
    }
}
