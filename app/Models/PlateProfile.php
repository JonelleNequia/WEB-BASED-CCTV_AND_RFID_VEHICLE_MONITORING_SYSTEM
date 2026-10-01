<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 5 (visitor model): everything known about one plate seen by the
 * camera without a registered tag. Identified by plate_key ("ABC1234").
 * A misread plate merged into the right one keeps merged_into_id, so a later
 * read of the misread plate goes to the right profile.
 */
class PlateProfile extends Model
{
    use StoresLocalTime;

    protected $fillable = [
        'plate_key',
        'plate_number',
        'vehicle_type',
        'vehicle_color',
        'note',
        'note_updated_by',
        'first_seen_at',
        'last_seen_at',
        'visit_count',
        'merged_into_id',
        'merged_by',
        'merged_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'merged_at' => 'datetime',
            'visit_count' => 'integer',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(VisitorRecord::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Plates merged into this one. */
    public function aliases(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    public function noteAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'note_updated_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }
}
