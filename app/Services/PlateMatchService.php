<?php

namespace App\Services;

use App\Models\PlateCorrection;
use App\Models\PlateProfile;
use App\Models\User;
use App\Models\VisitorRecord;
use App\Support\PhilippineTime;
use App\Support\PlateNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Gate direction work, Phase C: is this read the same vehicle as a plate
 * already seen, at this gate or another?
 *
 * A read one character off a known plate (the camera mixed up B and 8, or
 * missed a character) is tied to it when the rest agrees:
 *
 *   high    an easy OCR mix-up (O/0, I/1, B/8, S/5, Z/2, ... or one a guard
 *           corrected before), same vehicle type AND colour, a sensible time
 *   medium  an easy mix-up with the type OR the colour the same, or any one
 *           character off with type AND colour the same, at a sensible time
 *   low     anything else one character off (or two plates fit equally):
 *           NOT tied; it waits in Visitors › Review with the suggestion
 *
 * A sensible time: the known plate's last pass was the other way (IN then
 * OUT, or OUT then IN), earlier the same day, at least MIN_GAP_SECONDS before.
 *
 * Guard corrections are remembered (PlateCorrection): the same misread
 * later becomes the right plate at once ("learned").
 */
class PlateMatchService
{
    /** OCR mix-ups that are easy to make (both ways). */
    public const EASY_MIXUPS = [['O', '0'], ['D', '0'], ['Q', '0'], ['I', '1'], ['L', '1'], ['B', '8'], ['S', '5'], ['Z', '2'], ['G', '6'], ['T', '7'], ['A', '4']];

    /** The same vehicle cannot go IN and back OUT faster than this. */
    public const MIN_GAP_SECONDS = 30;

    public const EXACT = 'exact';

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    public const MANUAL = 'manual';

    public const LEARNED = 'learned';

    public const LABELS = [
        self::EXACT => 'Same plate',
        self::HIGH => 'Matched (high)',
        self::MEDIUM => 'Matched (medium)',
        self::LOW => 'Possible match (check)',
        self::MANUAL => 'Typed by a guard',
        self::LEARNED => 'Corrected as before',
    ];

    /**
     * The right plate key for a read a guard corrected before (the most
     * corrected one), or null.
     */
    public function learnedPlateKey(?string $readKey): ?string
    {
        if (blank($readKey)) {
            return null;
        }

        return PlateCorrection::query()->where('read_key', $readKey)->where('plate_key', '!=', $readKey)
            ->orderByDesc('times')->orderByDesc('updated_at')->value('plate_key');
    }

    /** Remember a guard's correction of a camera read. */
    public function learn(?string $read, ?string $plate, ?User $user): void
    {
        $readKey = PlateNumber::key($read);
        $plateKey = PlateNumber::key($plate);

        if ($readKey === null || $plateKey === null || $readKey === $plateKey) {
            return;
        }

        $correction = PlateCorrection::query()->firstOrNew(['read_key' => $readKey, 'plate_key' => $plateKey]);
        $correction->times = $correction->exists ? $correction->times + 1 : 1;
        $correction->last_corrected_by = $user?->id;
        $correction->save();
    }

    /**
     * The known plate this read is probably, with how sure:
     * {profile, confidence (high|medium|low), reason}, or null when no
     * current plate is one character off.
     *
     * @return array{profile: PlateProfile, confidence: string, reason: string}|null
     */
    public function nearMatch(VisitorRecord $record, string $readKey): ?array
    {
        $length = strlen($readKey);
        $candidates = PlateProfile::query()->current()
            ->whereRaw('length(plate_key) between ? and ?', [$length - 1, $length + 1])
            ->where('plate_key', '!=', $readKey)
            ->get()
            ->map(fn (PlateProfile $profile) => ['profile' => $profile, 'difference' => $this->difference($readKey, $profile->plate_key)])
            ->filter(fn (array $candidate) => $candidate['difference'] !== null)
            ->map(fn (array $candidate) => $candidate + $this->judge($record, $candidate['profile'], $candidate['difference']))
            ->sortByDesc('score')
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = $candidates->first();
        $confidence = $best['confidence'];
        $reason = $best['reason'];

        // Two plates fit equally well: a guard decides.
        if ($candidates->count() > 1 && $candidates[1]['score'] === $best['score'] && $confidence !== self::LOW) {
            $confidence = self::LOW;
            $reason = 'Two known plates fit equally ('.$best['profile']->plate_number.', '.$candidates[1]['profile']->plate_number.')';
        }

        return ['profile' => $best['profile'], 'confidence' => $confidence, 'reason' => mb_substr($reason, 0, 160)];
    }

    /**
     * The one difference between two plate keys: ['easy' => bool, 'text' => "8 read as B"],
     * or null when they differ by more than one character.
     *
     * @return array{easy: bool, text: string}|null
     */
    public function difference(string $read, string $known): ?array
    {
        if (strlen($read) === strlen($known)) {
            $positions = array_keys(array_diff_assoc(str_split($read), str_split($known)));
            if (count($positions) !== 1) {
                return null;
            }
            [$got, $right] = [$read[$positions[0]], $known[$positions[0]]];

            return ['easy' => $this->easyMixup($got, $right), 'text' => "{$right} read as {$got}"];
        }

        [$short, $long] = strlen($read) < strlen($known) ? [$read, $known] : [$known, $read];
        for ($i = 0; $i < strlen($long); $i++) {
            if (substr($long, 0, $i).substr($long, $i + 1) === $short) {
                return ['easy' => false, 'text' => strlen($read) < strlen($known) ? "missed {$long[$i]}" : "extra {$long[$i]}"];
            }
        }

        return null;
    }

    public function easyMixup(string $a, string $b): bool
    {
        foreach (self::EASY_MIXUPS as [$x, $y]) {
            if (($a === $x && $b === $y) || ($a === $y && $b === $x)) {
                return true;
            }
        }

        return $this->learnedMixups()->contains($a.$b) || $this->learnedMixups()->contains($b.$a);
    }

    /**
     * Character swaps from guard corrections ("8B" when 8 was corrected to B).
     *
     * @return Collection<int, string>
     */
    protected function learnedMixups(): Collection
    {
        return once(fn () => PlateCorrection::query()->get(['read_key', 'plate_key'])
            ->filter(fn (PlateCorrection $correction) => strlen($correction->read_key) === strlen($correction->plate_key))
            ->flatMap(function (PlateCorrection $correction): array {
                $pairs = [];
                foreach (str_split($correction->read_key) as $i => $character) {
                    if ($character !== $correction->plate_key[$i]) {
                        $pairs[] = $character.$correction->plate_key[$i];
                    }
                }

                return $pairs;
            })->unique()->values());
    }

    /**
     * @param  array{easy: bool, text: string}  $difference
     * @return array{score: int, confidence: string, reason: string}
     */
    protected function judge(VisitorRecord $record, PlateProfile $profile, array $difference): array
    {
        $sameType = filled($record->vehicle_type) && strcasecmp((string) $record->vehicle_type, (string) $profile->vehicle_type) === 0;
        $sameColor = filled($record->vehicle_color) && strcasecmp((string) $record->vehicle_color, (string) $profile->vehicle_color) === 0;
        [$timeOk, $timeText] = $this->sensibleTime($record, $profile);

        $confidence = match (true) {
            ! $timeOk => self::LOW,
            $difference['easy'] && $sameType && $sameColor => self::HIGH,
            $difference['easy'] && ($sameType || $sameColor) => self::MEDIUM,
            ! $difference['easy'] && $sameType && $sameColor => self::MEDIUM,
            default => self::LOW,
        };
        $score = ($timeOk ? 4 : 0) + ($difference['easy'] ? 2 : 0) + ($sameType ? 1 : 0) + ($sameColor ? 1 : 0);
        $parts = [
            $profile->plate_number.' with '.$difference['text'],
            $sameType ? 'same type' : 'type differs',
            $sameColor ? 'same colour' : 'colour differs',
            $timeText,
        ];

        return ['score' => $score, 'confidence' => $confidence, 'reason' => implode('; ', $parts)];
    }

    /**
     * The known plate's last pass before this one was the other way, the
     * same day, and not just now.
     *
     * @return array{0: bool, 1: string}
     */
    protected function sensibleTime(VisitorRecord $record, PlateProfile $profile): array
    {
        $seenAt = $record->seen_at;
        if (! in_array($record->direction, ['IN', 'OUT'], true) || ! $seenAt) {
            return [false, 'direction unknown'];
        }

        $last = VisitorRecord::query()->active()->where('plate_profile_id', $profile->id)
            ->whereKeyNot($record->id)->where('seen_at', '<', $seenAt)->latest('seen_at')->first();
        if (! $last) {
            return [false, 'no earlier pass to pair with'];
        }
        if ($last->direction === $record->direction || ! in_array($last->direction, ['IN', 'OUT'], true)) {
            return [false, 'its last pass was also '.$last->direction];
        }
        if (PhilippineTime::localDateString($last->seen_at) !== PhilippineTime::localDateString($seenAt)) {
            return [false, 'its last pass was another day'];
        }
        $gap = (int) $last->seen_at->diffInSeconds($seenAt, true);
        if ($gap < self::MIN_GAP_SECONDS) {
            return [false, "its last pass was only {$gap} s before"];
        }

        return [true, $last->direction.' at '.\App\Models\Gate::labelFor($last->gate).' '.$this->ago($last->seen_at, $seenAt).' before'];
    }

    protected function ago(CarbonInterface $from, CarbonInterface $to): string
    {
        $minutes = (int) round($from->diffInMinutes($to, true));

        return $minutes >= 60 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : $minutes.' min';
    }
}
