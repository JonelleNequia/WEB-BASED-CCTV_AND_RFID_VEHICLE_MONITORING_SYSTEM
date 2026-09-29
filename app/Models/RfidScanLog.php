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
    ];

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

        if ($this->verification_status === 'guest') {
            return 'Guest';
        }

        // Phase 3: guest pass outcomes.
        $guestPassLabel = match ($this->verification_status) {
            'guest_pass_available' => 'Guest Pass (issue)',
            'guest_pass_entry' => 'Guest Pass Entry',
            'guest_pass_exit' => 'Guest Pass Exit',
            'guest_pass_duplicate' => 'Guest Pass (ignored)',
            'guest_pass_not_issued' => 'Guest Pass not issued',
            'guest_pass_lost' => 'Lost Guest Pass',
            'guest_pass_disabled' => 'Disabled Guest Pass',
            default => null,
        };

        if ($guestPassLabel !== null) {
            return $guestPassLabel;
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
            'guest', 'inactive_tag', 'inactive_vehicle', 'non_recurring_category', 'unassigned_tag',
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
        return ucfirst($this->scan_location);
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
        return $this->resolved_event_type ?: strtoupper($this->scan_direction ?: 'N/A');
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
