<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class VehicleEvent extends Model
{
    use HasFactory;
    use StoresLocalTime;

    public const STATUS_PENDING_DETAILS = 'pending_details';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REQUIRES_MANUAL_REVIEW = 'requires_manual_review';

    /** Phase 5: match_status of a detector "Vehicle with no pass" alert. */
    public const MATCH_NO_PASS_ALERT = 'no_pass_alert';

    public const MATCH_NO_PASS_RESOLVED = 'no_pass_resolved';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_type',
        'event_status',
        'event_origin',
        'direction',
        'plate_number',
        'plate_text',
        'plate_confidence',
        'vehicle_id',
        'rfid_scan_log_id',
        'vehicle_type',
        'detected_vehicle_type',
        'vehicle_color',
        'vehicle_category',
        'camera_id',
        'external_event_key',
        'detection_metadata_json',
        'details_completed_at',
        'roi_name',
        'event_time',
        'vehicle_image_path',
        'plate_image_path',
        'matched_entry_id',
        'match_score',
        'match_status',
        'resulting_state',
        'daily_entries_count',
        'daily_exits_count',
        // Phase 2: link to a guest pass visit.
        'guest_visit_id',
        // Phase 3: why this event needs review.
        'anomaly_reason',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'plate_confidence' => 'decimal:2',
            'match_score' => 'integer',
            'detection_metadata_json' => 'array',
            'details_completed_at' => 'datetime',
            'daily_entries_count' => 'integer',
            'daily_exits_count' => 'integer',
        ];
    }

    /**
     * Get the camera linked to the event.
     */
    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    /**
     * Get the registered vehicle linked to this event.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the RFID scan correlated to this event.
     */
    public function rfidScanLog(): BelongsTo
    {
        return $this->belongsTo(RfidScanLog::class);
    }

    /**
     * Phase 8 (visitor model): hide the camera "guest" log copies of archived
     * guest records (unknown-tag reads); with $visitorCopies also those of
     * crossings that are shown as Unregistered Visitor records instead.
     */
    public function scopeWithoutHiddenGuestCopies(Builder $query, bool $visitorCopies = false): Builder
    {
        return $query->where(function (Builder $inner) use ($visitorCopies): void {
            $inner->whereNotIn('event_origin', ['guest_cctv', 'guest_manual'])
                ->orWhereNull('external_event_key')
                ->orWhere(function (Builder $copy) use ($visitorCopies): void {
                    $copy->whereNotIn('external_event_key', GuestVehicleObservation::query()->withArchived()
                        ->whereNotNull('archived_at')->whereNotNull('external_event_key')->select('external_event_key'));

                    if ($visitorCopies) {
                        $copy->whereNotIn('external_event_key', VisitorRecord::query()->select('external_event_key'));
                    }
                });
        });
    }

    /**
     * Phase 2: the guest pass visit this event belongs to.
     */
    public function guestVisit(): BelongsTo
    {
        return $this->belongsTo(GuestVisit::class);
    }

    /**
     * Phase 4: log fields that replace "GUEST / Owner N/A" for guest pass events.
     *
     * @return array<string, string>
     */
    public static function guestPassLogFields(self $event): array
    {
        $visit = $event->guestVisit;

        if (! $visit) {
            return [];
        }

        $passLabel = $visit->rfidTag?->label ?? 'Guest Pass';

        return [
            'plate_number' => $visit->plate ?: ($visit->rfidTag?->display_number ?? 'GUEST'),
            'owner_name' => $visit->driver_name ?: 'Guest',
            'verification_label' => $passLabel,
            'resulting_state' => $event->event_type === 'EXIT' ? 'Outside' : 'Inside',
        ];
    }

    /**
     * Phase 1 (gates): a camera event's type comes from the crossing
     * direction the detector reports (IN / OUT), not from the gate, because
     * every gate records both. Unknown -> ENTRY until Phase 2 calibrates the
     * IN side of each gate.
     */
    public static function eventTypeForDirection(mixed $direction): string
    {
        return strtoupper(trim((string) $direction)) === 'OUT' ? 'EXIT' : 'ENTRY';
    }

    /**
     * Phase 5: station log fields for a detector "Vehicle with no pass" alert.
     *
     * @return array<string, mixed>
     */
    public static function noPassLogFields(self $event): array
    {
        if ($event->event_origin !== 'guest_cctv') {
            return [];
        }

        return [
            'plate_number' => $event->plate_text ?: 'NO PLATE READ',
            'owner_name' => 'Unknown',
            'verification_label' => 'NO PASS',
            'resulting_state' => 'N/A',
            // Older CCTV guest rows (before Phase 5) keep their old status and do not alert.
            'no_pass_alert' => $event->match_status === self::MATCH_NO_PASS_ALERT,
            // Phase 1: the gate whose camera raised the alert.
            'alert_location' => $event->camera?->camera_role,
            'snapshot_url' => $event->vehicle_image_path
                ? Storage::disk('public')->url($event->vehicle_image_path)
                : null,
        ];
    }

    /**
     * Phase 4: "Guest Pass #G-03" instead of the generic origin label.
     */
    public function getSourceDisplayLabelAttribute(): string
    {
        if ($this->event_origin === 'guest_pass' && $this->guestVisit?->rfidTag) {
            return $this->guestVisit->rfidTag->label;
        }

        return $this->event_origin_label;
    }

    /**
     * Get the matched entry candidate for an exit event.
     */
    public function matchedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'matched_entry_id');
    }

    /**
     * Get the active session created by an entry event.
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(ActiveSession::class, 'entry_event_id');
    }

    /**
     * Resolve the public URL for the vehicle image with a safe fallback.
     */
    public function getVehicleImageUrlAttribute(): string
    {
        if ($this->vehicle_image_path && Storage::disk('public')->exists($this->vehicle_image_path)) {
            return Storage::disk('public')->url($this->vehicle_image_path);
        }

        return asset('images/placeholders/vehicle-placeholder.svg');
    }

    /**
     * Resolve the public URL for the plate image with a safe fallback.
     */
    public function getPlateImageUrlAttribute(): string
    {
        if ($this->plate_image_path && Storage::disk('public')->exists($this->plate_image_path)) {
            return Storage::disk('public')->url($this->plate_image_path);
        }

        return asset('images/placeholders/plate-placeholder.svg');
    }

    /**
     * Show the workflow status for log tables and badges.
     */
    public function getDisplayStatusAttribute(): string
    {
        if ($this->event_status === self::STATUS_PENDING_DETAILS) {
            return self::STATUS_PENDING_DETAILS;
        }

        return $this->match_status ?: self::STATUS_COMPLETED;
    }

    /**
     * Show movement-friendly labels instead of internal session states.
     */
    public function getDisplayStatusLabelAttribute(): string
    {
        return match ($this->display_status) {
            'open' => 'Entry',
            'closed' => 'Exit',
            self::MATCH_NO_PASS_ALERT => 'No-pass Alert',
            self::MATCH_NO_PASS_RESOLVED => 'Resolved (pass issued)',
            default => str($this->display_status)->replace('_', ' ')->title()->value(),
        };
    }

    /**
     * Show the vehicle type that came from detection or manual completion.
     */
    public function getDisplayVehicleTypeAttribute(): string
    {
        return $this->detected_vehicle_type ?: $this->vehicle_type ?: 'N/A';
    }

    /**
     * Determine if the log came from the RFID-first workflow.
     */
    public function getIsRfidEventAttribute(): bool
    {
        return in_array($this->event_origin, ['rfid_simulated', 'rfid_hardware'], true);
    }

    /**
     * Determine if actual visual evidence files are attached.
     */
    public function getHasVisualEvidenceAttribute(): bool
    {
        return (filled($this->vehicle_image_path) && Storage::disk('public')->exists($this->vehicle_image_path))
            || (filled($this->plate_image_path) && Storage::disk('public')->exists($this->plate_image_path));
    }

    /**
     * Show a compact match label for logs and detail pages.
     */
    public function getMatchDisplayAttribute(): string
    {
        if ($this->event_status === self::STATUS_PENDING_DETAILS) {
            return 'Pending';
        }

        if ($this->event_origin === 'guest_cctv') {
            return 'No pass';
        }

        if ($this->vehicle_category === 'guest' || $this->event_origin === 'guest_manual') {
            return 'Guest';
        }

        if ($this->event_type === 'ENTRY') {
            return $this->is_rfid_event ? 'State based' : 'N/A';
        }

        if ($this->is_rfid_event) {
            return 'State based';
        }

        return $this->match_score !== null ? (string) $this->match_score : 'N/A';
    }

    /**
     * Show a readable event origin label for the UI.
     */
    public function getEventOriginLabelAttribute(): string
    {
        return match ($this->event_origin) {
            'cctv_detected' => 'CCTV Observation',
            'guest_manual' => 'Guest Manual',
            'guest_cctv' => 'No-pass Alert',
            'rfid_simulated' => 'RFID Scan',
            'rfid_hardware' => 'RFID Reader',
            'guest_pass' => 'Guest Pass',
            default => 'Manual Log',
        };
    }

    /**
     * Show one readable state result for vehicle movement summaries.
     */
    public function getResultingStateLabelAttribute(): string
    {
        if (! $this->resulting_state) {
            return 'N/A';
        }

        return ucfirst(strtolower($this->resulting_state));
    }

    /**
     * Resolve a simple camera role label for display.
     */
    public function getCameraRoleLabelAttribute(): string
    {
        $role = $this->camera?->camera_role;

        if (! $role) {
            return 'No Camera Linked';
        }

        return Gate::labelFor($role).' Camera';
    }

    /**
     * Resolve a badge class name for the current workflow status.
     */
    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->display_status) {
            self::STATUS_PENDING_DETAILS => 'pending-details',
            'manual_review' => 'manual-review',
            'guest' => 'manual-review',
            self::MATCH_NO_PASS_ALERT => 'unmatched',
            self::MATCH_NO_PASS_RESOLVED => 'closed',
            'matched' => 'matched',
            'unmatched' => 'unmatched',
            'closed' => 'closed',
            'open' => 'open',
            default => 'secondary',
        };
    }
}
