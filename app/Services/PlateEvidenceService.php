<?php

namespace App\Services;

use App\Models\User;
use App\Models\VisitorRecord;

/** Character confidence is not proof of identifier type. Uses existing evidence JSON. */
class PlateEvidenceService
{
    public const KEY = '_application_plate_evidence';

    public const TEMPORARY = ['temporary_plate' => 'Temporary plate', 'conduction_sticker' => 'Conduction sticker', 'mv_file' => 'MV file number'];

    public function observe(VisitorRecord $record, array $data): void
    {
        $details = (array) $record->ocr_details_json;
        $evidence = (array) ($details[self::KEY] ?? []);
        if (! isset($evidence['source']) && $this->staffConfirmed($record)) {
            // Preserve pre-Phase-3 staff decisions when late OCR first adds this
            // metadata. No installation-wide rewrite or inferred detector type.
            $evidence = array_replace($evidence, ['kind' => $record->plate_number ? 'actual_plate' : 'none',
                'source' => 'staff', 'text' => $record->plate_number, 'by' => $record->corrected_by,
                'at' => $record->corrected_at?->toIso8601String(), 'legacy_decision' => true]);
        }
        $raw = (array) ($data['ocr_details'] ?? []);
        unset($raw[self::KEY], $raw[VehicleTypeEvidenceService::KEY]);
        $observation = array_intersect_key($data, array_flip(['plate_number', 'plate_status', 'plate_confidence', 'best_guess']));
        $observation['ocr_details'] = $raw;
        $hash = hash('sha256', json_encode($observation, JSON_THROW_ON_ERROR));
        $evidence['original'] ??= $observation;
        $evidence['original_hash'] ??= $hash;
        if ($hash !== $evidence['original_hash'] && ! in_array($hash, array_column($evidence['later_reads'] ?? [], 'hash'), true)) {
            // Retain the original plus bounded distinct late diagnostics; retries are not new decisions.
            $evidence['later_reads'][] = ['hash' => $hash, 'evidence' => $observation];
            $evidence['later_reads'] = array_slice($evidence['later_reads'], -10);
        }
        if (($evidence['source'] ?? null) !== 'staff') {
            $evidence['kind'] = 'uncertain';
            $evidence['source'] = 'detector';
            $evidence['latest'] = $observation;
            $decision = $this->reviewDecision($data);
            if (self::candidateFromData($data) !== null) {
                $evidence['candidate_confidence'] = $data['plate_confidence'] ?? null;
            } elseif (self::candidate($record) !== null) {
                $decision = ['code' => 'earlier_ocr_candidate', 'reason' => 'Earlier OCR candidate retained; the latest read supplied no usable text. Confirm it from the occurrence images.'];
            }
            $evidence['reason'] = $decision['reason'];
            $evidence['reason_code'] = $decision['code'];
        }
        $record->ocr_details_json = array_replace($details, [self::KEY => $evidence]);
    }

    public function reviewReason(array $data): string
    {
        return $this->reviewDecision($data)['reason'];
    }

    private function reviewDecision(array $data): array
    {
        $vote = (array) data_get($data, 'ocr_details.vote', []);
        $candidate = self::candidateFromData($data);
        if ($candidate === null) {
            return ['code' => 'ocr_no_text', 'reason' => 'No usable plate text was read. Inspect the snapshot and plate crop.'];
        }
        $key = \App\Support\PlateNumber::storageKey($candidate);
        if (! $key || mb_strlen(\App\Support\PlateNumber::display($candidate) ?? '') > 30) {
            return ['code' => 'ocr_invalid_text', 'reason' => 'OCR candidate contains unsupported characters or exceeds the plate limits. Confirm the literal text and identifier type from the images.'];
        }
        if (isset($vote['status']) && $vote['status'] !== 'read') {
            $why = is_string($vote['reason'] ?? null) ? mb_substr($vote['reason'], 0, 240) : 'OCR observations are uncertain.';
            return ['code' => 'ocr_vote_uncertain', 'reason' => $why.' Confirm the candidate and identifier type from the images.'];
        }
        if (! isset($data['plate_confidence']) || $data['plate_confidence'] < VisitorRecord::LOW_PLATE_CONFIDENCE || $data['plate_confidence'] > 1) {
            return ['code' => 'ocr_low_confidence', 'reason' => 'OCR confidence is missing or below the existing review threshold. Confirm the candidate and identifier type.'];
        }
        if (isset(self::TEMPORARY[data_get($data, 'ocr_details.identifier_evidence.kind', '')])) {
            return ['code' => 'temporary_identifier_evidence', 'reason' => 'The observed text is marked as a temporary identifier: evidence only, not an accepted actual plate. Confirm the identifier type from the images.'];
        }
        if (($vote['status'] ?? $data['plate_status'] ?? null) !== 'read') {
            return ['code' => 'ocr_uncertain', 'reason' => 'OCR produced a candidate but did not accept the text. Confirm the candidate and identifier type from the images.'];
        }

        return ['code' => 'identifier_type_unconfirmed', 'reason' => 'Identifier type is unconfirmed. Inspect the images: confirm an actual plate or record a temporary identifier. Text confidence and a Registry match do not prove the type.'];
    }

    /** Display evidence without promoting it into plate_number or a matching key. */
    public static function candidate(VisitorRecord $record): ?string
    {
        if (app(self::class)->staffConfirmed($record) || self::label($record)
            || $record->plate_status !== VisitorRecord::PLATE_UNREADABLE) return null;
        $details = (array) $record->ocr_details_json;
        $latest = data_get($details, self::KEY.'.latest', data_get($details, self::KEY.'.original', []));
        foreach ([$record->ocr_plate_number, data_get($latest, 'best_guess'), data_get($latest, 'plate_number'),
            data_get($details, 'vote.best_guess'), data_get($details, 'vote.plate')] as $value) {
            if ($text = self::candidateText($value)) return $text;
        }
        return null;
    }

    private static function candidateText(mixed $value): ?string
    {
        if (! is_string($value) || mb_strlen($value) > 30 || preg_match('/[\p{Cc}\p{Cf}]/u', $value)
            || ! preg_match('/[A-Za-z0-9]/', $value)) return null;

        return \App\Support\PlateNumber::display($value);
    }

    public static function candidateFromData(array $data): ?string
    {
        foreach ([$data['plate_number'] ?? null, $data['best_guess'] ?? null,
            data_get($data, 'ocr_details.vote.plate'), data_get($data, 'ocr_details.vote.best_guess')] as $value) {
            if ($text = self::candidateText($value)) return $text;
        }
        return null;
    }

    public static function presentation(VisitorRecord $record): array
    {
        $candidate = self::candidate($record);
        $reason = $record->registration_reason;
        $evidence = (array) data_get($record->ocr_details_json, self::KEY, []);
        $code = $reason === ($evidence['reason'] ?? null) ? ($evidence['reason_code'] ?? null) : null;
        $earlier = ($evidence['reason_code'] ?? null) === 'earlier_ocr_candidate';
        $confidence = $earlier ? ($evidence['candidate_confidence'] ?? data_get($evidence, 'original.plate_confidence'))
            : ($record->plate_confidence ?? $evidence['candidate_confidence'] ?? data_get($evidence, 'original.plate_confidence'));
        // Render older evidence correctly without rewriting historical decisions.
        // A real RFID/Registry conflict or staff decision keeps its existing reason.
        if ($record->classificationCategory() === 'review' && ! app(self::class)->staffConfirmed($record)
            && ($evidence['source'] ?? null) === 'detector'
            && (! $reason || preg_match('/^(OCR |Identifier type |Plate unreadable,|No usable plate text|The observed text)/', $reason))) {
            $latest = $evidence['latest'] ?? $evidence['original'] ?? [];
            $data = array_replace($latest, ['best_guess' => $candidate, 'plate_confidence' => $record->plate_confidence,
                'ocr_details' => (array) $record->ocr_details_json]);
            $decision = app(self::class)->reviewDecision($data);
            $reason = $decision['reason']; $code = $decision['code'];
            if ($candidate && $code === 'ocr_no_text') {
                $code = 'earlier_ocr_candidate';
                $reason = 'Earlier OCR candidate retained; the latest read supplied no usable text. Confirm it from the occurrence images.';
            }
        }
        return ['candidate' => $candidate, 'reason' => $reason, 'reason_code' => $code,
            'source' => $candidate ? ($earlier ? 'Earlier OCR candidate (unconfirmed)' : 'OCR candidate (unconfirmed)') : null,
            'confidence' => is_numeric($confidence) && $confidence >= 0 && $confidence <= 1 ? (float) $confidence : null];
    }

    public function confirm(VisitorRecord $record, string $kind, ?string $text, User $user): void
    {
        $record->ocr_details_json = array_replace((array) $record->ocr_details_json, [self::KEY => array_replace(
            (array) data_get($record->ocr_details_json, self::KEY, []),
            ['kind' => $kind, 'source' => 'staff', 'text' => $text, 'by' => $user->id, 'at' => now()->toIso8601String()]
        )]);
    }

    public function staffConfirmed(VisitorRecord $record): bool
    {
        return data_get($record->ocr_details_json, self::KEY.'.source') === 'staff'
            || (data_get($record->ocr_details_json, self::KEY.'.source') === null
                && ($record->plate_status === VisitorRecord::PLATE_CORRECTED || ($record->reviewed_at !== null && $record->corrected_by !== null)));
    }

    public static function kind(VisitorRecord $record): ?string
    {
        return data_get($record->ocr_details_json, self::KEY.'.kind')
            ?? (app(self::class)->staffConfirmed($record) ? ($record->plate_number ? 'actual_plate' : 'none') : null);
    }

    public static function label(VisitorRecord $record): ?string
    {
        return self::TEMPORARY[self::kind($record)] ?? null;
    }

    public static function rawReads(VisitorRecord $record): array
    {
        $details = (array) $record->ocr_details_json;
        $reads = $details['raw_reads'] ?? null;
        if (! is_array($reads)) {
            $reads = [];
            foreach ((array) ($details['frame_results'] ?? []) as $frame) {
                if (is_array($frame) && is_array($frame['raw_reads'] ?? null)) {
                    $reads = array_merge($reads, $frame['raw_reads']);
                }
            }
        }
        if (! $reads) {
            $reads = data_get($details, self::KEY.'.original.ocr_details.raw_reads', []);
        }

        return array_slice(array_values(array_filter(is_array($reads) ? $reads : [], fn ($read) => is_array($read) && is_string($read['text'] ?? null))), 0, 20);
    }
}
