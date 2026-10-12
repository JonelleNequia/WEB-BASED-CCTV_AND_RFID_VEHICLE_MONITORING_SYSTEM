<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROI work, Phase 2: no IN arrow either. Only the zone is drawn; each gate
 * says whether a vehicle moving AWAY from the camera (its box shrinks and
 * goes up the picture) is going IN or OUT of campus.
 *
 * The saved IN arrow (or an older trigger line) of each gate's camera
 * becomes that setting, then the arrow and line data are removed. A gate
 * whose direction cannot be worked out stays "not set" (every pass is then
 * Direction unknown, and its card says so).
 *
 * Staff can set the direction of a "Direction unknown" record; each change
 * is kept in direction_corrections (who, when, from what).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gates', function (Blueprint $table): void {
            $table->string('away_direction', 3)->nullable()->after('pass_min_move');
        });

        foreach (DB::table('cameras')->get() as $camera) {
            $away = self::awayFromSaved($camera);
            if ($away !== null) {
                DB::table('gates')->where('code', $camera->camera_role)->whereNull('away_direction')->update(['away_direction' => $away]);
            }
        }

        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn(['calibration_direction_json', 'calibration_line_json']);
        });

        Schema::create('direction_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visitor_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_crossing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gate', 30)->nullable();
            $table->string('detected_direction', 10)->nullable();
            $table->string('corrected_direction', 10);
            $table->string('detected_reason', 160)->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direction_corrections');
        Schema::table('cameras', function (Blueprint $table): void {
            $table->json('calibration_line_json')->nullable();
            $table->json('calibration_direction_json')->nullable();
        });
        Schema::table('gates', function (Blueprint $table): void {
            $table->dropColumn('away_direction');
        });
    }

    /**
     * "IN" / "OUT" for a vehicle moving away from the camera (up the
     * picture), from the camera's saved IN arrow or trigger line; null when
     * there is none or it points sideways.
     */
    public static function awayFromSaved(object $camera): ?string
    {
        $decode = fn ($value) => is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : null);
        $arrow = $decode($camera->calibration_direction_json ?? null);

        if (! is_array($arrow) || ! isset($arrow['y1'], $arrow['y2'])) {
            $line = $decode($camera->calibration_line_json ?? null);
            $arrow = is_array($line) ? self::arrowFromLine($line) : null;
        }

        return is_array($arrow) ? self::awayFromArrow($arrow) : null;
    }

    /**
     * The IN arrow points down the picture (toward the camera): away = OUT;
     * up: away = IN. Sideways (16:9 picture): null.
     *
     * @param  array<string, mixed>  $arrow
     */
    public static function awayFromArrow(array $arrow): ?string
    {
        $dx = ((float) ($arrow['x2'] ?? 0) - (float) ($arrow['x1'] ?? 0)) * 16.0;
        $dy = ((float) ($arrow['y2'] ?? 0) - (float) ($arrow['y1'] ?? 0)) * 9.0;

        if (abs($dy) < 1e-6 || abs($dy) < abs($dx)) {
            return null;
        }

        return $dy > 0 ? 'OUT' : 'IN';
    }

    /**
     * The IN direction of a trigger line: across it, toward its IN side
     * (side +1 is to the right of the line's direction in the picture).
     *
     * @param  array<string, mixed>  $line
     * @return array{x1: float, y1: float, x2: float, y2: float}|null
     */
    public static function arrowFromLine(array $line): ?array
    {
        if (! isset($line['x1'], $line['y1'], $line['x2'], $line['y2'])) {
            return null;
        }
        $dx = ((float) $line['x2'] - (float) $line['x1']) * 16.0;
        $dy = ((float) $line['y2'] - (float) $line['y1']) * 9.0;
        $length = sqrt($dx * $dx + $dy * $dy);
        if ($length <= 0) {
            return null;
        }
        $side = (int) ($line['in_side'] ?? 1) < 0 ? -1 : 1;

        return ['x1' => 0.0, 'y1' => 0.0, 'x2' => (-$dy / $length) * $side / 16.0, 'y2' => ($dx / $length) * $side / 9.0];
    }
};
