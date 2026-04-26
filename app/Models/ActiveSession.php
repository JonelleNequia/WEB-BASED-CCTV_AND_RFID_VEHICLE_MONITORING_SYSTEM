<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveSession extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entry_event_id',
        'plate_text',
        'vehicle_type',
        'vehicle_color',
        'entry_time',
        'status',
    ];

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_time' => 'datetime',
        ];
    }

    /**
     * Get the entry event linked to this session.
     */
    public function entryEvent(): BelongsTo
    {
        return $this->belongsTo(VehicleEvent::class, 'entry_event_id');
    }
}
