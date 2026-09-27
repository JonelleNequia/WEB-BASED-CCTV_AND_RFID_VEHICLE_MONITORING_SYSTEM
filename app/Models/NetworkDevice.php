<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A camera, UHF reader or other host found by the device service.
 * Identified by MAC address (device_key); the IP is updated on every scan.
 */
class NetworkDevice extends Model
{
    use StoresLocalTime;

    public const KIND_CAMERA = 'camera';

    public const KIND_READER = 'rfid_reader';

    public const KIND_ROUTER = 'router';

    public const KIND_UNKNOWN = 'unknown';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const KIND_LABELS = [
        self::KIND_CAMERA => 'CCTV',
        self::KIND_READER => 'RFID Reader',
        self::KIND_ROUTER => 'Router',
        self::KIND_UNKNOWN => 'Unknown',
    ];

    protected $fillable = [
        'device_key', 'mac', 'ip', 'kind', 'confidence', 'status', 'vendor', 'brand', 'model', 'name',
        'interface', 'subnet', 'details', 'is_new', 'first_seen_at', 'last_seen_at', 'ip_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'is_new' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ip_changed_at' => 'datetime',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? 'Unknown';
    }

    public function isReachable(): bool
    {
        return $this->status !== self::STATUS_UNREACHABLE;
    }

    /**
     * @return array<string, mixed>
     */
    public function cameraDetails(): array
    {
        return (array) ($this->details['camera'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function readerDetails(): array
    {
        return (array) ($this->details['reader'] ?? []);
    }
}
