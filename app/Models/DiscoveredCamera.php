<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CCTV Detection: added. One CCTV camera found on the local network.
 */
class DiscoveredCamera extends Model
{
    public const AUTH_OK = 'ok';

    public const AUTH_REQUIRED = 'auth_required';

    public const AUTH_FAILED = 'auth_failed';

    public const AUTH_VERIFYING = 'verifying';

    public const OFFLINE_AFTER_SECONDS = 30;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_key',
        'ip_address',
        'mac_address',
        'onvif_xaddr',
        'manufacturer',
        'model',
        'firmware_version',
        'serial_number',
        'name',
        'discovery_methods',
        'auth_status',
        'username',
        'password',
        'main_stream_uri',
        'sub_stream_uri',
        'pending_role',
        'thumbnail_file',
        'thumbnail_updated_at',
        'last_error',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * The camera password never leaves the server in JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'discovery_methods' => 'array',
            'password' => 'encrypted',
            'thumbnail_updated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The entrance or exit camera row this device is assigned to.
     */
    public function assignedCamera(): HasOne
    {
        return $this->hasOne(Camera::class, 'discovered_camera_id');
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }

    public function isReady(): bool
    {
        return $this->auth_status === self::AUTH_OK && filled($this->main_stream_uri);
    }

    public function needsCredentials(): bool
    {
        return in_array($this->auth_status, [self::AUTH_REQUIRED, self::AUTH_FAILED], true);
    }

    public function getDisplayNameAttribute(): string
    {
        $label = trim(implode(' ', array_filter([$this->manufacturer, $this->model])));

        return $label !== '' ? $label : ($this->name ?: 'IP Camera');
    }
}
