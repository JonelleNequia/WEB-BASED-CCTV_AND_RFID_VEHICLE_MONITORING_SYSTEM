<?php

namespace App\Services;

use App\Models\ActiveSession;
use App\Models\VehicleEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MatchingService
{
    public function __construct(
        protected SettingsService $settingsService
    ) {
    }

    /**
     * Score every open entry session and return the best candidate for an exit event.
     *
     * @return array{matched_entry_id:int|null,match_score:int,match_status:string,session:ActiveSession|null}
     */
    public function matchExitEvent(VehicleEvent $exitEvent): array
    {
        $sessions = ActiveSession::query()
            ->with(['entryEvent.camera'])
            ->where('status', 'open')
            ->orderBy('entry_time')
            ->get();

        if ($sessions->isEmpty()) {
            return [
                'matched_entry_id' => null,
                'match_score' => 0,
                'match_status' => 'unmatched',
                'session' => null,
            ];
        }

        $bestCandidate = $this->resolveBestCandidate($exitEvent, $sessions);
        $matchedThreshold = $this->settingsService->getInt('matching_threshold_matched', 75);
        $manualThreshold = $this->settingsService->getInt('matching_threshold_manual_review', 50);

        $status = 'unmatched';

        // The thresholds are intentionally simple so the capstone demo is easy to explain.
        if ($bestCandidate['score'] >= $matchedThreshold) {
            $status = 'matched';
        } elseif ($bestCandidate['score'] >= $manualThreshold) {
            $status = 'manual_review';
        }

        return [
            'matched_entry_id' => $status === 'unmatched' ? null : $bestCandidate['session']?->entry_event_id,
            'match_score' => $bestCandidate['score'],
            'match_status' => $status,
            'session' => $bestCandidate['session'],
        ];
    }

    /**
     * Pick the highest scoring open session.
     *
     * @param  Collection<int, ActiveSession>  $sessions
     * @return array{session:ActiveSession|null,score:int}
     */
    protected function resolveBestCandidate(VehicleEvent $exitEvent, Collection $sessions): array
    {
        $bestSession = null;
        $bestScore = 0;

        foreach ($sessions as $session) {
            $score = $this->scoreCandidate($exitEvent, $session);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSession = $session;
            }
        }

        return [
            'session' => $bestSession,
            'score' => $bestScore,
        ];
    }

    /**
     * Apply the weighted matching rules for one exit-to-entry comparison.
     */
    protected function scoreCandidate(VehicleEvent $exitEvent, ActiveSession $session): int
    {
        $entryEvent = $session->entryEvent;

        if (! $entryEvent) {
            return 0;
        }

        $score = 0;
        $entryPlate = $this->normalizePlate($session->plate_text);
        $exitPlate = $this->normalizePlate($exitEvent->plate_text);

        if ($entryPlate !== '' && $exitPlate !== '') {
            if ($entryPlate === $exitPlate) {
                $score += 60;
            } else {
                similar_text($entryPlate, $exitPlate, $percent);

                if ($percent >= 40) {
                    $score += (int) round(min(40, ($percent / 100) * 40));
                }
            }
        }

        if ($this->sameText($session->vehicle_type, $exitEvent->vehicle_type)) {
            $score += 15;
        }

        if ($this->sameText($session->vehicle_color, $exitEvent->vehicle_color)) {
            $score += 10;
        }

        if ($this->hasPlausibleTimeGap($session->entry_time, $exitEvent->event_time)) {
            $score += 10;
        }

        if ($this->sameRoute($entryEvent, $exitEvent)) {
            $score += 5;
        }

        return $score;
    }

    /**
     * Normalize a manual plate entry before matching.
     */
    protected function normalizePlate(?string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plate ?? ''));
    }

    /**
     * Compare text fields in a case-insensitive way.
     */
    protected function sameText(?string $left, ?string $right): bool
    {
        return filled($left) && filled($right) && strcasecmp($left, $right) === 0;
    }

    /**
     * Keep time matching simple for the prototype while still rejecting impossible exits.
     */
    protected function hasPlausibleTimeGap(Carbon $entryTime, Carbon $exitTime): bool
    {
        if ($exitTime->lessThan($entryTime)) {
            return false;
        }

        return $entryTime->diffInMinutes($exitTime) <= 720;
    }

    /**
     * Give a small bonus when the route looks consistent.
     */
    protected function sameRoute(VehicleEvent $entryEvent, VehicleEvent $exitEvent): bool
    {
        if ($this->sameText($entryEvent->roi_name, $exitEvent->roi_name)) {
            return true;
        }

        $entryCamera = Str::lower($entryEvent->camera?->camera_name ?? '');
        $exitCamera = Str::lower($exitEvent->camera?->camera_name ?? '');

        return Str::contains($entryCamera, 'entrance') && Str::contains($exitCamera, 'exit');
    }
}
