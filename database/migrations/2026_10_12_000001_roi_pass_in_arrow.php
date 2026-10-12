<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROI work: no trigger line. Each gate keeps its zone and an IN arrow (the
 * direction into campus, tail -> head, normalized 0-1); a vehicle's pass
 * through the zone is IN or OUT by how far it moved along that arrow.
 *
 * A saved trigger line becomes an arrow across it, pointing to its IN side
 * (in_side), so no gate loses its direction. Gates get their own minimum
 * movement along the arrow (percent of the zone; empty = the default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->json('calibration_direction_json')->nullable()->after('calibration_line_json');
        });
        Schema::table('gates', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pass_min_move')->nullable()->after('is_active');
        });

        foreach (DB::table('cameras')->whereNotNull('calibration_line_json')->get(['id', 'calibration_line_json']) as $camera) {
            $arrow = self::arrowFromLine(json_decode((string) $camera->calibration_line_json, true));
            if ($arrow !== null) {
                DB::table('cameras')->where('id', $camera->id)->update(['calibration_direction_json' => json_encode($arrow)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn('calibration_direction_json');
        });
        Schema::table('gates', function (Blueprint $table): void {
            $table->dropColumn('pass_min_move');
        });
    }

    /**
     * The arrow through the line's middle, at right angles to it in the
     * picture (16:9), towards its IN side, 40% of the picture's height long.
     *
     * @param  array<string, mixed>|null  $line
     * @return array{x1: float, y1: float, x2: float, y2: float}|null
     */
    public static function arrowFromLine(?array $line): ?array
    {
        if (! is_array($line) || ! isset($line['x1'], $line['y1'], $line['x2'], $line['y2'])) {
            return null;
        }
        [$aspectX, $aspectY] = [16.0, 9.0];
        $dx = ((float) $line['x2'] - (float) $line['x1']) * $aspectX;
        $dy = ((float) $line['y2'] - (float) $line['y1']) * $aspectY;
        $length = sqrt($dx * $dx + $dy * $dy);
        if ($length <= 0) {
            return null;
        }
        $side = (int) ($line['in_side'] ?? 1) < 0 ? -1 : 1;
        // Side +1 is to the right of the line's direction in image coordinates.
        $nx = (-$dy / $length) * $side;
        $ny = ($dx / $length) * $side;
        $half = 0.2 * $aspectY;
        $midX = ((float) $line['x1'] + (float) $line['x2']) / 2 * $aspectX;
        $midY = ((float) $line['y1'] + (float) $line['y2']) / 2 * $aspectY;
        $clamp = fn (float $value): float => round(max(0.0, min(1.0, $value)), 4);

        return [
            'x1' => $clamp(($midX - $nx * $half) / $aspectX),
            'y1' => $clamp(($midY - $ny * $half) / $aspectY),
            'x2' => $clamp(($midX + $nx * $half) / $aspectX),
            'y2' => $clamp(($midY + $ny * $half) / $aspectY),
        ];
    }
};
