<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfidScanLog extends Model
{
    use HasFactory;
    use StoresLocalTime;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'vehicle_id',
        'vehicle_rfid_tag_id',
        'correlated_vehicle_event_id',
        'guest_vehicle_observation_id',
        'tag_uid',
        'scan_location',
        'scan_direction',
        'resolved_event_type',
        'resulting_state',
        'vehicle_category',
        'reader_name',
        'scan_time',
        'verification_status',
        'source_mode',
        'payload_json',
        'payload_file_path',
        'notes',
        // Phase 3: review flags and guest pass link.
        'is_anomaly',
        'anomaly_reason',
        'outcome',
        'guest_visit_id',
        // Phase 3 (visitor model): RFID + camera fusion.
        'vehicle_crossing_id',
        'fusion_status',
        'fusion_note',
        'detector_event_key',
    ];

    /** Phase 3: waiting for the camera's crossing (no movement recorded yet). */
    public const FUSION_PENDING = 'pending';

    /** Direction from the camera's crossing. */
    public const FUSION_CAMERA = 'camera';

    /** Direction from the vehicle's state (no camera, camera offline, direction unknown). */
    public const FUSION_TOGGLE = 'toggle';

    /** The camera was watching but saw no crossing: no movement recorded. */
    public const FUSION_SCAN_ONLY = 'scan_only';

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scan_time' => 'datetime',
            'payload_json' => 'array',
            'is_anomaly' => 'boolean',
        ];
    }

    /**
     * Registered vehicle linked to this scan.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * RFID tag used during the scan.
     */
    public function vehicleRfidTag(): BelongsTo
    {
        return $this->belongsTo(VehicleRfidTag::class);
    }

    /**
     * Correlated CCTV or manual vehicle event, when available.
     */
    public function correlatedVehicleEvent(): BelongsTo
    {
        return $this->belongsTo(VehicleEvent::class, 'correlated_vehicle_event_id');
    }

    /**
     * Phase 3: the camera crossing that gave this scan its direction.
     */
    public function vehicleCrossing(): BelongsTo
    {
        return $this->belongsTo(VehicleCrossing::class);
    }

    /**
     * Phase 3: where the direction of this scan came from.
     */
    public function getFusionLabelAttribute(): ?string
    {
        return match ($this->fusion_status) {
            self::FUSION_PENDING => 'Waiting for the camera',
            self::FUSION_CAMERA => 'Direction from camera',
            self::FUSION_TOGGLE => 'Direction from vehicle state',
            self::FUSION_SCAN_ONLY => 'Scan only (no crossing seen)',
            default => null,
        };
    }

    /**
     * Phase 3: an unknown tag (not in the registry): one event per cooldown,
     * with "Register this tag". Older scans stored it as "guest" with no tag.
     */
    public function isUnknownTag(): bool
    {
        return $this->verification_status === 'unknown_tag'
            || ($this->verification_status === 'guest' && $this->vehicle_rfid_tag_id === null && $this->vehicle_id === null);
    }

    /**
     * Guest observation created when a guest tag needs CCTV review.
     */
    public function guestVehicleObservation(): BelongsTo
    {
        return $this->belongsTo(GuestVehicleObservation::class);
    }

    /**
     * Phase 3: the guest pass visit this scan belongs to.
     */
    public function guestVisit(): BelongsTo
    {
        return $this->belongsTo(GuestVisit::class);
    }

    /**
     * Show a readable verification label for the UI.
     */
    public function getVerificationLabelAttribute(): string
    {
        if ($this->verification_status === 'verified') {
            return 'Registered';
        }

        if ($this->isUnknownTag()) {
            return 'Unknown tag';
        }

        // A vehicle still in the old Guest category: its Registry entry needs a category.
        if ($this->verification_status === 'guest') {
            return 'Needs category';
        }

        return str_replace('_', ' ', ucfirst($this->verification_status));
    }

    /**
     * Resolve a badge class for the verification status.
     */
    public function getVerificationBadgeClassAttribute(): string
    {
        return match ($this->verification_status) {
            'verified', 'guest_pass_entry', 'guest_pass_exit' => 'matched',
            'guest', 'unknown_tag', 'inactive_tag', 'inactive_vehicle', 'non_recurring_category', 'unassigned_tag',
            'guest_pass_available', 'guest_pass_duplicate' => 'manual-review',
            default => 'unmatched',
        };
    }

    /**
     * Show a readable label for the source mode.
     */
    public function getSourceModeLabelAttribute(): string
    {
        return match ($this->source_mode) {
            'hardware_placeholder' => 'Hardware Reader',
            default => ucfirst(str_replace('_', ' ', $this->source_mode)),
        };
    }

    /**
     * Show a readable label for the scan location.
     */
    public function getScanLocationLabelAttribute(): string
    {
        return Gate::labelFor($this->scan_location);
    }

    /**
     * Show a readable label for the scan direction.
     */
    public function getScanDirectionLabelAttribute(): string
    {
        return ucfirst($this->scan_direction ?: 'unknown');
    }

    /**
     * Show a readable label for the state-driven event type.
     */
    public function getResolvedEventTypeLabelAttribute(): string
    {
        if ($this->resolved_event_type) {
            return $this->resolved_event_type;
        }

        // Phase 3: no movement (waiting, scan only, unknown tag): scan_direction is only a placeholder.
        if ($this->fusion_status !== null || $this->verification_status === 'unknown_tag') {
            return 'No IN/OUT';
        }

        return strtoupper($this->scan_direction ?: 'N/A');
    }

    /**
     * Show a readable resulting state label for vehicle workflow views.
     */
    public function getResultingStateLabelAttribute(): string
    {
        if (! $this->resulting_state) {
            return 'N/A';
        }

        return ucfirst(strtolower($this->resulting_state));
    }
}
