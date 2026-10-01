<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which network device a gate uses: one camera and one reader per gate.
 * ("station" holds the gate code since Phase 1.)
 */
class DeviceAssignment extends Model
{
    use StoresLocalTime;

    /**
     * Phase 1: the active gate codes (replaces the fixed entrance/exit list).
     *
     * @return list<string>
     */
    public static function stations(): array
    {
        return Gate::codes();
    }

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
