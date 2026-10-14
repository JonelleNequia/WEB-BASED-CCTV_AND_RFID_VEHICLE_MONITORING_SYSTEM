<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gate direction work, Phase C: a camera read a guard corrected ("A8C1234"
 * -> "ABC1234"), and how many times. Later reads of the same text become the
 * right plate, and its character swaps (8 -> B) count as easy OCR mix-ups
 * when matching.
 */
class PlateCorrection extends Model
{
    protected $fillable = ['read_key', 'plate_key', 'times', 'last_corrected_by'];

    protected function casts(): array
    {
        return ['times' => 'integer'];
    }
}
