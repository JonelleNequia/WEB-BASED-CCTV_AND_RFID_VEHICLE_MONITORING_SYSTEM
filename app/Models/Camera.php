<?php

namespace App\Models;

use App\Casts\EncryptedSecret;
use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Camera extends Model
{
    use HasFactory;
    use StoresLocalTime;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'camera_name',
        'camera_role',
        'source_type',
        'source_value',
        'source_username',
        'source_password',
        'browser_device_id',
        'browser_label',
        'calibration_mask_json',
        'calibration_line_json',
        'last_connection_status',
        'last_connection_message',
        'last_connected_at',
        'status',
    ];

    /**
     * Plug-and-detect: never serialize the camera password.
     *
     * @var list<string>
     */
    protected $hidden = [
        'source_password',
    ];

    /**
     * Attribute casting for JSON calibration data and timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Plug-and-detect: stored encrypted with APP_KEY.
            'source_password' => EncryptedSecret::class,
            'calibration_mask_json' => 'array',
            'calibration_line_json' => 'array',
            'last_connected_at' => 'datetime',
        ];
    }

    /**
     * Filter cameras by their role in the vehicle flow.
     */
    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->where('camera_role', $role);
    }

    /**
     * Get the ROI records attached to this camera.
     */
    public function rois(): HasMany
    {
        return $this->hasMany(Roi::class);
    }

    /**
     * Get events captured by this camera.
     */
    public function vehicleEvents(): HasMany
    {
        return $this->hasMany(VehicleEvent::class);
    }

    /**
     * Get guest observations linked to this camera.
     */
    public function guestVehicleObservations(): HasMany
    {
        return $this->hasMany(GuestVehicleObservation::class);
    }
}
