<?php

namespace App\Services;

use App\Models\PlateProfile;
use App\Models\ReviewAction;
use App\Models\User;
use App\Models\VisitorRecord;
use App\Support\PlateNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gate direction work, Phase D: Visitors › Review, one card at a time.
 *
 * What waits: a plate to check (unreadable, read with a low confidence, or
 * only a low match to a known plate) that no guard has looked at, and an
 * unknown direction. IN / OUT is counted as soon as the direction is known;
 * Review is for the plate and the matching, not for the count.
 *
 * Every action changes the record, the counts, the plate profile and the
 * ranking at once, and is kept in review_actions (who, when, the value
 * before and after).
 */
class VisitorReviewService
{
    public const ACTIONS = ['confirm', 'correct_plate', 'pick_match', 'no_plate', 'set_direction', 'set_name', 'dismiss'];

    public const DISMISS_REASONS = ['not_vehicle' => 'Not a vehicle', 'duplicate' => 'Duplicate of another pass'];

    public function __construct(
        protected VisitorRecordService $visitorRecordService,
        protected DirectionCorrectionService $directionCorrectionService
    ) {
    }

    /**
     * The cards, newest first.
     *
     * @return Collection<int, VisitorRecord>
     */
    public function queue(int $limit = 100): Collection
    {
        return VisitorRecord::query()->needsReview()
            ->with(['plateProfile', 'suggestedProfile', 'crossing'])
            ->latest('seen_at')->limit($limit)->get();
    }

    public function count(): int
    {
        return VisitorRecord::query()->needsReview()->count();
    }

    /**
     * Known plates like the read (the Phase C suggestion first), with how
     * many times each passed: [{profile, distance}], at most $limit.
     *
     * @return Collection<int, array{profile: PlateProfile, distance: int}>
     */
    public function suggestions(VisitorRecord $record, int $limit = 3): Collection
    {
        $key = PlateNumber::key($record->plate_number ?: $record->ocr_plate_number);
        $suggested = $record->suggestedProfile;
        $found = collect($suggested ? [['profile' => $suggested, 'distance' => $key ? levenshtein($key, $suggested->plate_key) : 1]] : []);

        if ($key !== null) {
            $length = strlen($key);
            $found = $found->concat(
                PlateProfile::query()->current()
                    ->whereRaw('length(plate_key) between ? and ?', [$length - 2, $length + 2])
                    ->when($record->plate_profile_id, fn ($query) => $query->whereKeyNot($record->plate_profile_id))
                    ->when($suggested, fn ($query) => $query->whereKeyNot($suggested->id))
                    ->get()
                    ->map(fn (PlateProfile $profile) => ['profile' => $profile, 'distance' => levenshtein($key, $profile->plate_key)])
                    ->filter(fn (array $item) => $item['distance'] <= 2)
                    ->sortBy(fn (array $item) => [$item['distance'], -$item['profile']->visit_count])
            );
        }

        return $found->take($limit)->values();
    }

    /**
     * One Review action. $input: plate (correct_plate), profile_id
     * (pick_match), direction (set_direction), name (set_name), reason
     * (dismiss). Returns what to tell the guard and what still waits.
     *
     * @param  array<string, mixed>  $input
     * @return array{message: string, needs: array{plate: bool, direction: bool}, remaining: int}
     */
    public function act(VisitorRecord $record, string $action, array $input, User $user): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Choose an action.']);
        }

        $message = DB::transaction(fn (): string => match ($action) {
            'confirm' => $this->confirm($record, $user),
            'correct_plate' => $this->correctPlate($record, (string) ($input['plate'] ?? ''), $user, 'correct_plate'),
            'pick_match' => $this->pickMatch($record, (int) ($input['profile_id'] ?? 0), $user),
            'no_plate' => $this->noPlate($record, $user),
            'set_direction' => $this->setDirection($record, (string) ($input['direction'] ?? ''), $user),
            'set_name' => $this->setName($record, (string) ($input['name'] ?? ''), $user),
            'dismiss' => $this->dismiss($record, (string) ($input['reason'] ?? ''), $user),
        });

        $record->refresh();

        return [
            'message' => $message,
            'needs' => [
                'plate' => $record->plateNeedsReview(),
                'direction' => $record->status === VisitorRecord::STATUS_ACTIVE && $record->direction === 'UNKNOWN',
            ],
            'remaining' => $this->count(),
        ];
    }

    public function log(VisitorRecord $record, string $action, ?string $from, ?string $to, ?User $user, ?int $profileId = null): ReviewAction
    {
        return ReviewAction::query()->create([
            'visitor_record_id' => $record->id,
            'plate_profile_id' => $profileId ?? $record->plate_profile_id,
            'action' => $action,
            'from_value' => $from !== null ? mb_substr($from, 0, 200) : null,
            'to_value' => $to !== null ? mb_substr($to, 0, 200) : null,
            'user_id' => $user?->id,
        ]);
    }

    /**
     * @return Collection<int, ReviewAction>
     */
    public function recent(int $limit = 20): Collection
    {
        return ReviewAction::query()->with(['user', 'record'])->latest('id')->limit($limit)->get();
    }

    /** The camera's read is right (also a low match: it is its own plate). */
    protected function confirm(VisitorRecord $record, User $user): string
    {
        $plate = $record->plate_number;
        if (! $plate || ! in_array($record->plate_status, [VisitorRecord::PLATE_READ, VisitorRecord::PLATE_CORRECTED], true)) {
            throw ValidationException::withMessages(['plate' => 'There is no plate to confirm: type it, or press "No plate visible".']);
        }

        $before = $this->describe($record);
        $oldProfileId = $record->plate_profile_id;
        $profile = $record->plateProfile ?? $this->visitorRecordService->profileFor($plate, $record->seen_at);
        $record->forceFill([
            'plate_profile_id' => $profile->id,
            'vehicle_id' => $profile->vehicle_id,
            'suggested_profile_id' => null,
            'match_confidence' => $record->match_confidence === PlateMatchService::LOW || $record->match_confidence === null ? PlateMatchService::CONFIRMED : $record->match_confidence,
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ])->save();
        $this->visitorRecordService->refreshProfiles([$oldProfileId, $profile->id]);
        $this->log($record, 'confirm', $before, $plate, $user, $profile->id);

        return "Record #{$record->id}: plate {$plate} confirmed.";
    }

    protected function correctPlate(VisitorRecord $record, string $plate, User $user, string $action): string
    {
        $plate = trim($plate);
        if ($plate === '' || preg_match('/^[A-Za-z0-9 -]{2,30}$/', $plate) !== 1) {
            throw ValidationException::withMessages(['plate' => 'Type the plate: letters, digits, spaces or a dash.']);
        }

        $before = $this->describe($record);
        $record = $this->visitorRecordService->correctPlate($record, $plate, $user);
        $this->log($record, $action, $before, $record->plate_number, $user);

        return "Record #{$record->id}: plate set to {$record->plate_number}.";
    }

    protected function pickMatch(VisitorRecord $record, int $profileId, User $user): string
    {
        $profile = PlateProfile::query()->find($profileId);
        if (! $profile) {
            throw ValidationException::withMessages(['profile_id' => 'That plate is not in the list any more.']);
        }

        return $this->correctPlate($record, (string) $profile->plate_number, $user, 'pick_match');
    }

    protected function noPlate(VisitorRecord $record, User $user): string
    {
        $before = $this->describe($record);
        $record = $this->visitorRecordService->correctPlate($record, null, $user);
        $this->log($record, 'no_plate', $before, null, $user);

        return "Record #{$record->id}: no plate visible (still counted).";
    }

    protected function setDirection(VisitorRecord $record, string $direction, User $user): string
    {
        $record = $this->directionCorrectionService->setForVisitorRecord($record, $direction, $user);
        $this->log($record, 'set_direction', 'UNKNOWN', $record->direction, $user);

        return "Record #{$record->id} set to {$record->direction}.";
    }

    protected function setName(VisitorRecord $record, string $name, User $user): string
    {
        $profile = $record->plateProfile;
        if (! $profile) {
            throw ValidationException::withMessages(['name' => 'Save the plate first: the name is kept with the plate.']);
        }
        $name = trim($name);
        if (mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'At most 120 characters.']);
        }

        $before = $profile->owner_name;
        $profile->forceFill(['owner_name' => $name !== '' ? $name : null])->save();
        $this->log($record, 'set_name', $before, $profile->owner_name, $user, $profile->id);

        return $profile->owner_name ? "{$profile->plate_number}: name saved ({$profile->owner_name})." : "{$profile->plate_number}: name removed.";
    }

    protected function dismiss(VisitorRecord $record, string $reason, User $user): string
    {
        if (! array_key_exists($reason, self::DISMISS_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why: not a vehicle, or a duplicate.']);
        }

        $before = $record->status;
        $this->visitorRecordService->dismiss($record, self::DISMISS_REASONS[$reason].' ('.$user->name.')');
        $record->forceFill(['reviewed_at' => now(), 'reviewed_by' => $user->id])->save();
        $this->log($record, 'dismiss', $before, self::DISMISS_REASONS[$reason], $user);

        return "Record #{$record->id} dismissed: ".strtolower(self::DISMISS_REASONS[$reason]).' (no longer counted).';
    }

    /** "ABC 1234 (read, 54%)", "unreadable (guess ABC 1Z34)". */
    protected function describe(VisitorRecord $record): string
    {
        $confidence = $record->plate_confidence !== null ? ', '.round((float) $record->plate_confidence * 100).'%' : '';

        return $record->plate_number
            ? $record->plate_number.' ('.$record->plate_status.$confidence.')'
            : $record->plate_status.($record->ocr_plate_number ? ' (guess '.$record->ocr_plate_number.')' : '');
    }
}
