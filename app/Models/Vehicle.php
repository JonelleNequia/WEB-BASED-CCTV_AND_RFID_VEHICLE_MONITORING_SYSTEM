<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;

    public const STATE_INSIDE = 'inside';

    public const STATE_OUTSIDE = 'outside';

    /**
     * Categories that can use the RFID recurring workflow.
     *
     * @var list<string>
     */
    public const RFID_RECURRING_CATEGORIES = [
        'parent',
        'student',
        'faculty_staff',
        'guard',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'rfid_tag_uid',
        'plate_number',
        'vehicle_owner_name',
        'category',
        'vehicle_type',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_count_date' => 'date',
            'entries_today_count' => 'integer',
            'exits_today_count' => 'integer',
            'first_entry_today_at' => 'datetime',
            'last_exit_today_at' => 'datetime',
            'last_entry_at' => 'datetime',
            'last_exit_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * RFID tags registered for this vehicle.
     */
    public function rfidTags(): HasMany
    {
        return $this->hasMany(VehicleRfidTag::class);
    }

    /**
     * RFID scans linked to this vehicle.
     */
    public function rfidScanLogs(): HasMany
    {
        return $this->hasMany(RfidScanLog::class);
    }

    /**
     * Vehicle events associated with this registered vehicle.
     */
    public function vehicleEvents(): HasMany
    {
        return $this->hasMany(VehicleEvent::class);
    }

    /**
     * Latest RFID tag assigned to this vehicle.
     */
    public function latestRfidTag(): HasOne
    {
        return $this->hasOne(VehicleRfidTag::class)->latestOfMany();
    }

    /**
     * Backward-compatible alias for older views/tests that still read owner_name.
     */
    public function getOwnerNameAttribute(): ?string
    {
        return $this->vehicle_owner_name;
    }

    /**
     * Determine if this vehicle is part of the recurring RFID workflow.
     */
    public function isRfidRecurring(): bool
    {
        return in_array($this->category, self::RFID_RECURRING_CATEGORIES, true);
    }
}
