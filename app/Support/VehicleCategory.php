<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Phase 4 (visitor model): the three vehicle categories.
 *
 * - Faculty & Staff and Registered Visitor: vehicles in the Registry (RFID tag).
 * - Unregistered Visitor: vehicles the camera sees without a registered tag;
 *   set by the system, never picked in the Registry.
 *
 * Older values still in history are shown with the new names: Parent,
 * Student and Guard became Registered Visitor; "guest" (camera, no tag) is an
 * Unregistered Visitor.
 */
final class VehicleCategory
{
    public const FACULTY_STAFF = 'faculty_staff';

    public const REGISTERED_VISITOR = 'registered_visitor';

    public const UNREGISTERED_VISITOR = 'unregistered_visitor';

    public const LABELS = [
        self::FACULTY_STAFF => 'Faculty & Staff',
        self::REGISTERED_VISITOR => 'Registered Visitor',
        self::UNREGISTERED_VISITOR => 'Unregistered Visitor',
    ];

    /** Categories that can be picked in the Registry. */
    public const REGISTRY = [self::FACULTY_STAFF, self::REGISTERED_VISITOR];

    /** Removed categories whose vehicles became Registered Visitors. */
    public const LEGACY_REGISTERED = ['parent', 'student', 'guard'];

    /** Older stored value for vehicles seen by the camera without a tag. */
    public const LEGACY_UNREGISTERED = ['guest'];

    public static function label(?string $category): string
    {
        $category = strtolower(trim((string) $category));

        if ($category === '') {
            return 'N/A';
        }

        return self::LABELS[self::normalize($category)]
            ?? ($category === 'guest_pass' ? 'Guest pass (removed)' : Str::of($category)->replace('_', ' ')->title()->value());
    }

    /**
     * A stored value in the new categories (unknown values stay as they are).
     */
    public static function normalize(?string $category): string
    {
        $category = strtolower(trim((string) $category));

        return match (true) {
            in_array($category, self::LEGACY_REGISTERED, true) => self::REGISTERED_VISITOR,
            in_array($category, self::LEGACY_UNREGISTERED, true) => self::UNREGISTERED_VISITOR,
            default => $category,
        };
    }

    /**
     * Every stored value that means this category (for filters on history).
     *
     * @return list<string>
     */
    public static function storedValues(string $category): array
    {
        return match ($category) {
            self::REGISTERED_VISITOR => [self::REGISTERED_VISITOR, ...self::LEGACY_REGISTERED],
            self::UNREGISTERED_VISITOR => [self::UNREGISTERED_VISITOR, ...self::LEGACY_UNREGISTERED],
            default => [$category],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function registryOptions(): array
    {
        return array_intersect_key(self::LABELS, array_flip(self::REGISTRY));
    }
}
