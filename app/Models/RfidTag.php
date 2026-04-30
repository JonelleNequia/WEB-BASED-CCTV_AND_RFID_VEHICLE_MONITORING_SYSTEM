<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfidTag extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'vehicle_rfid_tags';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uid',
        'status',
        'vehicle_id',
        'assigned_at',
        'last_scanned_at',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
