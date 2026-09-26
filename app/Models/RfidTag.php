<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RfidTag extends Model
{
    use HasFactory;
    use StoresLocalTime;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    // Phase 2: guest pass statuses and the renamed "inactive" status.
    public const STATUS_ISSUED = 'issued';

    public const STATUS_LOST = 'lost';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @deprecated Phase 2 renamed "inactive" to "disabled".
     */
    public const STATUS_INACTIVE = self::STATUS_DISABLED;

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ASSIGNED,
        self::STATUS_ISSUED,
        self::STATUS_LOST,
        self::STATUS_DISABLED,
    ];

    // Phase 2: tag types.
    public const TYPE_VEHICLE = 'vehicle';

    public const TYPE_GUEST_PASS = 'guest_pass';

    public const TYPES = [self::TYPE_VEHICLE, self::TYPE_GUEST_PASS];

    protected $table = 'vehicle_rfid_tags';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tag_number',
        'uid',
        'status',
        'vehicle_id',
        'assigned_at',
        'last_scanned_at',
        'tag_type',
        'display_number',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tag_number' => 'integer',
            'assigned_at' => 'datetime',
            'last_scanned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RfidTag $tag): void {
            $uid = $tag->uid ?: ($tag->attributes['tag_uid'] ?? null);

            if ($uid) {
                $normalizedUid = static::normalizeUid((string) $uid);
                $tag->uid = $normalizedUid;
                $tag->attributes['tag_uid'] = $normalizedUid;
            }

            if (! $tag->status) {
                $tag->status = self::STATUS_AVAILABLE;
            }

            // Phase 2: default type, and a guest pass is never tied to a vehicle.
            if (! $tag->tag_type) {
                $tag->tag_type = self::TYPE_VEHICLE;
            }

            if ($tag->tag_type === self::TYPE_GUEST_PASS) {
                $tag->vehicle_id = null;

                if (! $tag->display_number) {
                    $tag->display_number = static::nextGuestPassNumber();
                }
            }
        });
    }

    public static function normalizeUid(string $uid): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', '', $uid)));
    }

    public function getTagUidAttribute(?string $value): ?string
    {
        return $this->attributes['uid'] ?? $value;
    }

    public function setTagUidAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['tag_uid'] = null;
            $this->attributes['uid'] = null;

            return;
        }

        $normalizedUid = self::normalizeUid($value);
        $this->attributes['tag_uid'] = $normalizedUid;
        $this->attributes['uid'] = $normalizedUid;
    }

    /**
     * Vehicle currently assigned to this RFID tag.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Scan logs recorded for this tag.
     */
    public function scanLogs(): HasMany
    {
        return $this->hasMany(RfidScanLog::class, 'vehicle_rfid_tag_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ASSIGNED);
    }

    /**
     * Phase 2: tags meant for registered vehicles.
     */
    public function scopeVehicleTags(Builder $query): Builder
    {
        return $query->where('tag_type', self::TYPE_VEHICLE);
    }

    /**
     * Phase 2: reusable guest passes.
     */
    public function scopeGuestPasses(Builder $query): Builder
    {
        return $query->where('tag_type', self::TYPE_GUEST_PASS);
    }

    public function isGuestPass(): bool
    {
        return $this->tag_type === self::TYPE_GUEST_PASS;
    }

    /**
     * Phase 2: every issue of this pass.
     */
    public function guestVisits(): HasMany
    {
        return $this->hasMany(GuestVisit::class, 'rfid_tag_id');
    }

    /**
     * Phase 2: the visit currently holding this pass, if any.
     */
    public function activeGuestVisit(): HasOne
    {
        return $this->hasOne(GuestVisit::class, 'active_rfid_tag_id');
    }

    /**
     * Phase 2: next free guest pass label (G-01, G-02, ... G-100).
     */
    public static function nextGuestPassNumber(): string
    {
        $highest = static::query()
            ->where('display_number', 'like', 'G-%')
            ->pluck('display_number')
            ->map(fn (string $number): int => (int) substr($number, 2))
            ->max() ?? 0;

        return sprintf('G-%02d', $highest + 1);
    }

    /**
     * Phase 2: the label shown in logs and on the Guest Passes page.
     */
    public function getLabelAttribute(): string
    {
        if ($this->isGuestPass()) {
            return 'Guest Pass #'.($this->display_number ?: $this->uid);
        }

        return $this->tag_number ? 'Tag #'.$this->tag_number : (string) $this->uid;
    }
}
