<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which network device a station uses: one camera and one reader per station.
 */
class DeviceAssignment extends Model
{
    use StoresLocalTime;

    public const STATIONS = ['entrance', 'exit'];

    public const ROLE_CAMERA = 'camera';

    public const ROLE_READER = 'reader';

    protected $fillable = ['station', 'role', 'network_device_id', 'options'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }
}
