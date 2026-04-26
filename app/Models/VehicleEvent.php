<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class VehicleEvent extends Model
{
    use HasFactory;

    public const STATUS_PENDING_DETAILS = 'pending_details';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REQUIRES_MANUAL_REVIEW = 'requires_manual_review';

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
        if ($this->vehicle_image_path) {
            return Storage::disk('public')->url($this->vehicle_image_path);
        }

        return asset('images/placeholders/vehicle-placeholder.svg');
    }

    /**
     * Resolve the public URL for the plate image with a safe fallback.
     */
    public function getPlateImageUrlAttribute(): string
    {
        if ($this->plate_image_path) {
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
        return filled($this->vehicle_image_path) || filled($this->plate_image_path);
    }

    /**
     * Show a compact match label for logs and detail pages.
     */
    public function getMatchDisplayAttribute(): string
    {
        if ($this->event_status === self::STATUS_PENDING_DETAILS) {
            return 'Pending';
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
            'guest_cctv' => 'Guest CCTV',
            'rfid_simulated' => 'RFID Scan',
            'rfid_hardware' => 'RFID Reader',
            default => 'Manual Log',
        };
    }

    /**
     * Show one readable state result for parking movement summaries.
     */
    public function getResultingStateLabelAttribute(): string
    {
        if (! $this->resulting_state) {
            return 'N/A';
        }

        return ucfirst($this->resulting_state);
    }

    /**
     * Resolve a simple camera role label for display.
     */
    public function getCameraRoleLabelAttribute(): string
    {
        $role = $this->camera?->camera_role;

        if (! $role) {
            return 'Unknown Camera';
        }

        return ucfirst($role).' Camera';
    }

    /**
     * Resolve a badge class name for the current workflow status.
     */
    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->display_status) {
            self::STATUS_PENDING_DETAILS => 'pending-details',
            'manual_review' => 'manual-review',
            'matched' => 'matched',
            'unmatched' => 'unmatched',
            'closed' => 'closed',
            'open' => 'open',
            default => 'secondary',
        };
    }
}
