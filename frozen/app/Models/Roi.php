<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Roi extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'camera_id',
        'roi_name',
        'x',
        'y',
        'width',
        'height',
        'direction_type',
        'status',
    ];

    /**
     * Get the camera that owns this ROI.
     */
    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }
}
