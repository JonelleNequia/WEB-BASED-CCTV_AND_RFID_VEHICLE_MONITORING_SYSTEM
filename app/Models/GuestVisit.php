<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 2: one guest visit = one issue of a guest pass.
 *
 * The guest pass (an RFID tag with tag_type "guest_pass") is reused. It is
 * not tied to a person, so every issue creates a new visit.
 */
class GuestVisit extends Model
{
    use StoresLocalTime;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_OVERSTAY = 'overstay';

    public const STATUS_LOST_TAG = 'lost_tag';

    /**
     * Visits that still hold the pass (the guest is inside).
     */
    public const OPEN_STATUSES = [self::STATUS_ACTIVE, self::STATUS_OVERSTAY];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rfid_tag_id',
        'active_rfid_tag_id',
        'plate',
        'driver_name',
        'vehicle_type',
        'color',
        'purpose',
        'destination',
        'id_presented',
        'issued_by',
        'entry_at',
        'entry_snapshot',
        'exit_at',
        'exit_snapshot',
        'valid_until',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'entry_at' => 'datetime',
            'exit_at' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function rfidTag(): BelongsTo
    {
        return $this->belongsTo(RfidTag::class, 'rfid_tag_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function vehicleEvents(): HasMany
    {
        return $this->hasMany(VehicleEvent::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
