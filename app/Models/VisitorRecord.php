<?php

namespace App\Models;

use App\Models\Concerns\RecordsSourceDevice;
use App\Models\Concerns\StoresLocalTime;
use App\Support\VehicleCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 5 (visitor model): an Unregistered Visitor record, i.e. a vehicle the
 * camera saw cross a gate with no registered tag read. Unregistered visitors
 * are counted IN and OUT but never "inside".
 */
class VisitorRecord extends Model
{
    use StoresLocalTime;
    // Delete device work: remembers the camera / reader that made the record.
    use RecordsSourceDevice;

    public const STATUS_ACTIVE = 'active';

    /** The same vehicle sent twice (new track ID, same plate within a minute). */
    public const STATUS_DUPLICATE = 'duplicate';

    /** A registered tag was read for it after all, or a false alarm. */
    public const STATUS_DISMISSED = 'dismissed';

    public const PLATE_PENDING = 'pending';

    public const PLATE_READ = 'read';

    public const PLATE_UNREADABLE = 'unreadable';

    public const PLATE_CORRECTED = 'corrected';

    /**
     * Gate direction work, Phase B: a plate read below this confidence (0-1)
     * goes to Visitors › Review for a guard to check, like an unreadable one.
     */
    public const LOW_PLATE_CONFIDENCE = 0.6;

    public const CATEGORY = VehicleCategory::UNREGISTERED_VISITOR;

    /** Phase 8: where the record came from. */
    public const SOURCE_CAMERA = 'camera';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LEGACY = 'legacy';

    protected $fillable = [
        'direction_source',
        'direction_confidence',
        'match_confidence',
        'match_reason',
        'suggested_profile_id',
        'reviewed_at',
        'reviewed_by',
        'external_event_key',
        'source',
        'vehicle_crossing_id',
        'gate',
        'camera_id',
        'direction',
        'seen_at',
        'status',
        'status_note',
        'duplicate_of_id',
        'snapshot_path',
        'plate_image_path',
        'plate_status',
        'plate_number',
        // RFID only with a vehicle: an unknown tag read for this vehicle.
        'tag_uid',
        'plate_key',
        'plate_confidence',
        'ocr_plate_number',
        'ocr_details_json',
        'plate_profile_id',
        'vehicle_id',
        'vehicle_type',
        'vehicle_color',
        'corrected_by',
        'corrected_at',
    ];

    /**
     * Gate direction work, Phase B: plates a guard should check (unreadable,
     * or read with a low confidence), until a guard has looked at them.
     */
    public function scopePlateToCheck(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNull('reviewed_at')
            ->where(fn ($inner) => self::plateNeedsLook($inner));
    }

    /**
     * Gate direction work, Phase D: what waits in Visitors › Review: a plate
     * to check (not looked at yet) or an unknown direction.
     */
    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn ($inner) => $inner->where('direction', 'UNKNOWN')
                ->orWhere(fn ($plate) => $plate->whereNull('reviewed_at')->where(fn ($look) => self::plateNeedsLook($look))));
    }

    /** Unreadable, read with a low confidence, or only a low match to a known plate (Phase C). */
    protected static function plateNeedsLook($query)
    {
        return $query->where('plate_status', self::PLATE_UNREADABLE)
            ->orWhere(fn ($low) => $low->where('plate_status', self::PLATE_READ)->where('plate_confidence', '<', self::LOW_PLATE_CONFIDENCE))
            ->orWhere('match_confidence', \App\Services\PlateMatchService::LOW);
    }

    public function plateNeedsReview(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->reviewed_at === null
            && ($this->plate_status === self::PLATE_UNREADABLE
                || ($this->plate_status === self::PLATE_READ && $this->plate_confidence !== null && (float) $this->plate_confidence < self::LOW_PLATE_CONFIDENCE)
                || $this->match_confidence === \App\Services\PlateMatchService::LOW);
    }

    /** Phase C: the known plate this record probably is (a low match, waiting in Review). */
    public function suggestedProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlateProfile::class, 'suggested_profile_id');
    }

    protected static function booted(): void
    {
        // Gate direction work, Phase A: where IN / OUT came from (camera, state,
        // manual) when the code that saved it did not say; none while unknown.
        static::saving(function (VisitorRecord $record): void {
            if (! in_array($record->direction, ['IN', 'OUT'], true)) {
                $record->direction_source = null;
                $record->direction_confidence = null;

                return;
            }

            $record->direction_source ??= $record->source === self::SOURCE_MANUAL
                ? \App\Support\DirectionSource::MANUAL
                : \App\Support\DirectionSource::CAMERA;
            if ($record->direction_source !== \App\Support\DirectionSource::CAMERA) {
                $record->direction_confidence = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
            'corrected_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'plate_confidence' => 'float',
            'ocr_details_json' => 'array',
        ];
    }

    public function crossing(): BelongsTo
    {
        return $this->belongsTo(VehicleCrossing::class, 'vehicle_crossing_id');
    }

    public function plateProfile(): BelongsTo
    {
        return $this->belongsTo(PlateProfile::class);
    }

    /** Phase 6: the Registry vehicle this plate belongs to (registered later, or tag not read). */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function plateLabel(): string
    {
        return match ($this->plate_status) {
            self::PLATE_PENDING => 'Reading plate…',
            self::PLATE_UNREADABLE => 'Plate unreadable',
            default => (string) $this->plate_number,
        };
    }

    public function getSnapshotUrlAttribute(): ?string
    {
        return $this->publicUrl($this->snapshot_path);
    }

    public function getPlateImageUrlAttribute(): ?string
    {
        return $this->publicUrl($this->plate_image_path);
    }

    protected function publicUrl(?string $path): ?string
    {
        return $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path) : null;
    }

    public function sourceDeviceGate(): ?string
    {
        return $this->gate;
    }

    public function sourceDeviceRole(): string
    {
        return DeviceAssignment::ROLE_CAMERA;
    }
}
