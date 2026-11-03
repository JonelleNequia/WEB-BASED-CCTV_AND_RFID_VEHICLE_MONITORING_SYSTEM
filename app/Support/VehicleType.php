<?php

namespace App\Support;

use App\Rules\ValidVehicleType;
use Illuminate\Support\Facades\Validator;

/** Physical types, independent of registration categories. */
final class VehicleType
{
    public const CAR = 'Car';

    public const MOTORCYCLE = 'Motorcycle';

    public const TRUCK_BUS = 'Truck/Bus';

    public const TYPES = ['E-bike', 'E-trike', 'E-car', 'Car', 'Truck', 'Tricycle', 'Motorcycle',
        'Bus', 'Van', 'SUV', 'AUV', 'MPV', 'Pickup', 'Jeepney', 'Sedan', 'Hatchback',
        'Scooter', 'Electric scooter', 'Truck/Bus'];

    public const COMPARISON_GROUPS = [self::CAR, self::MOTORCYCLE, self::TRUCK_BUS];

    public const HINTS = [
        'E-bike' => 'Electric bicycle', 'E-trike' => 'Electric tricycle', 'E-car' => 'Electric car',
        'Car' => 'General car type', 'Truck' => 'Truck', 'Tricycle' => 'Tricycle / auto rickshaw',
        'Motorcycle' => 'Motorcycle / motorbike', 'Bus' => 'Bus', 'Van' => 'Van', 'SUV' => 'SUV',
        'AUV' => 'AUV', 'MPV' => 'MPV', 'Pickup' => 'Pickup truck', 'Jeepney' => 'Jeepney',
        'Sedan' => 'Sedan', 'Hatchback' => 'Hatchback', 'Scooter' => 'Scooter',
        'Electric scooter' => 'Electric scooter', 'Truck/Bus' => 'Broad or legacy truck/bus result',
    ];

    public static function canonical(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }
        $key = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $label))));
        foreach (self::TYPES as $type) {
            if ($key === strtolower(str_replace('-', ' ', $type))) {
                return $type;
            }
        }

        return match ($key) {
            'ebike', 'electric bike', 'electric bicycle' => 'E-bike',
            'etrike', 'electric trike', 'electric tricycle' => 'E-trike',
            'ecar', 'electric car' => 'E-car',
            'motorbike', 'motor cycle', 'motor' => self::MOTORCYCLE,
            'auto rickshaw' => 'Tricycle', 'pickup truck' => 'Pickup',
            'jeep' => 'Jeepney', 'car van' => self::CAR, 'truck / bus', 'truck bus' => self::TRUCK_BUS,
            default => null,
        };
    }

    /** Installed model road classes and the existing legacy broad result. */
    public static function detector(mixed $raw): ?string
    {
        $type = self::canonical($raw);

        return in_array($type, [self::CAR, self::MOTORCYCLE, 'Bus', 'Truck', self::TRUCK_BUS], true) ? $type : null;
    }

    public static function validated(mixed $value, ?string $legacy = null, bool $required = true): ?string
    {
        if (is_string($value) && filled($legacy) && $value === $legacy) {
            return $legacy;
        }
        Validator::make(['vehicle_type' => $value], ['vehicle_type' => [
            $required ? 'required' : 'nullable', 'string', 'max:50', new ValidVehicleType($legacy),
        ]])->validate();

        return $legacy !== null && $value === $legacy ? $legacy : self::canonical($value);
    }

    public static function display(?string $value): string
    {
        return self::canonical($value) ?? (filled($value) ? $value : 'Unknown');
    }

    public static function category(?string $value): ?string
    {
        return self::canonical($value);
    }

    /** Coarse diagnostic agreement only, never evidence of a specific subtype. */
    public static function comparisonGroup(?string $value): ?string
    {
        return match (self::canonical($value)) {
            'Car', 'E-car', 'Sedan', 'Hatchback', 'SUV', 'AUV', 'MPV', 'Pickup', 'Van' => self::CAR,
            'Motorcycle', 'Scooter', 'Electric scooter', 'E-bike', 'E-trike', 'Tricycle' => self::MOTORCYCLE,
            'Truck', 'Bus', 'Truck/Bus', 'Jeepney' => self::TRUCK_BUS,
            default => null,
        };
    }
}
