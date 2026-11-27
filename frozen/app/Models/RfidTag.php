<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RfidTag extends Model
{
    use HasFactory;
    use StoresLocalTime;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    // Phase 2: renamed "inactive" status. (Guest pass "issued" was removed in Phase 0.)
    public const STATUS_LOST = 'lost';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @deprecated Phase 2 renamed "inactive" to "disabled".
     */
    public const STATUS_INACTIVE = self::STATUS_DISABLED;

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ASSIGNED,
        self::STATUS_LOST,
        self::STATUS_DISABLED,
    ];

    // Every tag is a vehicle tag (guest passes were removed in Phase 0; the
    // tag_type column stays for old records).
    public const TYPE_VEHICLE = 'vehicle';

    public const TYPES = [self::TYPE_VEHICLE];

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
            $stored = $tag->exists ? DB::table($tag->getTable())->where('id', $tag->id)->first() : null;
            $oldUid = self::normalizeUid((string) ($stored?->uid ?: $stored?->tag_uid));
            $uid = $tag->uid ?: ($tag->attributes['tag_uid'] ?? null);

            if ($uid) {
                $normalizedUid = static::normalizeUid((string) $uid);
                $tag->uid = $normalizedUid;
                $tag->attributes['tag_uid'] = $normalizedUid;
            }

            if (! $tag->status) {
                $tag->status = self::STATUS_AVAILABLE;
            }

            if (! $tag->tag_type) {
                $tag->tag_type = self::TYPE_VEHICLE;
            }
            if (($stored?->status === self::STATUS_LOST || ($oldUid !== '' && self::isUnavailableUid($oldUid)))
                && ($tag->status !== self::STATUS_LOST || $tag->uid !== $oldUid)) {
                throw ValidationException::withMessages(['status' => 'Lost / Unavailable tags cannot be reactivated or changed.']);
            }
            if (self::isUnavailableUid((string) $tag->uid) && (! $stored || $oldUid !== $tag->uid)) {
                throw ValidationException::withMessages(['uid' => 'This UID is permanently Lost / Unavailable. Use a different tag.']);
            }
            if ($tag->status === self::STATUS_LOST) {
                DB::table('unavailable_rfid_uids')->insertOrIgnore([
                    'uid' => $tag->uid, 'tag_id' => $stored?->id,
                    'marked_by' => auth()->id(), 'marked_at' => now(), 'previous_status' => $stored?->status,
                ]);
            }
        });
        static::deleting(function (RfidTag $tag): void {
            if (self::isUnavailableUid((string) $tag->uid)) {
                throw ValidationException::withMessages(['uid' => 'Lost / Unavailable tag history must be retained.']);
            }
        });
    }

    public static function normalizeUid(string $uid): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', '', $uid)));
    }

    public static function isUnavailableUid(string $uid): bool
    {
        $uid = self::normalizeUid($uid);

        return $uid !== '' && (DB::table('unavailable_rfid_uids')->where('uid', $uid)->exists()
            || self::query()->where('status', self::STATUS_LOST)->get(['uid', 'tag_uid'])->contains(
                fn (self $tag) => self::normalizeUid((string) $tag->uid) === $uid
                    || self::normalizeUid((string) $tag->getRawOriginal('tag_uid')) === $uid
            ));
    }

    /** Return no arbitrary identity when legacy canonical UIDs collide. */
    public static function findUid(string $uid): ?self
    {
        $matches = self::matchingUid($uid);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public static function matchingUid(string $uid): \Illuminate\Support\Collection
    {
        $uid = self::normalizeUid($uid);
        return self::query()->with('vehicle')->get()->filter(fn (self $tag) => self::normalizeUid((string) $tag->uid) === $uid
            || self::normalizeUid((string) $tag->getRawOriginal('tag_uid')) === $uid);
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status === self::STATUS_LOST || self::isUnavailableUid((string) $this->uid)
            ? 'Lost / Unavailable' : ucfirst($this->status);
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
        $uid = 'upper(uid)';
        foreach ([' ', "\t", "\n", "\r", "\v", "\f"] as $space) {
            $uid = "replace($uid, '".$space."', '')";
        }

        return $query->where('status', self::STATUS_AVAILABLE)
            ->whereNotIn(DB::raw($uid), DB::table('unavailable_rfid_uids')->select('uid'));
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
     * The label shown in logs ("Tag #7").
     */
    public function getLabelAttribute(): string
    {
        return $this->number_label !== null ? 'Tag #'.$this->number_label : (string) $this->uid;
    }

    /** Printed sticker text, with the existing numeric inventory number as the legacy fallback. */
    public function getNumberLabelAttribute(): ?string
    {
        return filled($this->display_number) ? (string) $this->display_number : ($this->tag_number ? (string) $this->tag_number : null);
    }
}
