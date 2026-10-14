<?php

namespace App\Support;

/**
 * Gate direction work, Phase A: where a record's IN / OUT came from.
 *
 * - camera: the vehicle moved away from or toward the camera (with its
 *   confidence, high or medium), mapped by the gate's setting
 * - state:  a registered vehicle's Inside / Outside (Outside -> IN,
 *   Inside -> OUT) when the camera could not tell, or had no camera
 * - manual: set or entered by staff
 */
final class DirectionSource
{
    public const CAMERA = 'camera';

    public const STATE = 'state';

    public const MANUAL = 'manual';

    public const LABELS = [
        self::CAMERA => 'From the camera',
        self::STATE => 'batay sa state',
        self::MANUAL => 'Set by staff',
    ];

    public static function label(?string $source): ?string
    {
        return self::LABELS[$source] ?? null;
    }

    /** "high" / "medium" from a crossing's details, or null. */
    public static function confidence(mixed $value): ?string
    {
        return in_array($value, ['high', 'medium'], true) ? $value : null;
    }
}
