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
            $evidence['reason'] = $this->reviewReason($data);
        }
        $record->ocr_details_json = array_replace($details, [self::KEY => $evidence]);
    }

    public function reviewReason(array $data): string
    {
        $candidate = $data['plate_number'] ?? $data['best_guess'] ?? null;
        $key = \App\Support\PlateNumber::storageKey($candidate);
        if (! $key || mb_strlen(\App\Support\PlateNumber::display($candidate) ?? '') > 30) {
            return 'Plate unreadable, incomplete or invalid. Inspect the OCR evidence and images.';
        }
        if (($data['plate_status'] ?? null) !== 'read') {
            $why = data_get($data, 'ocr_details.vote.reason');

            return 'OCR result requires review. '.(is_string($why) ? mb_substr($why, 0, 240).' ' : '').'Confirm the text and identifier type from the images.';
        }
        if (! isset($data['plate_confidence']) || $data['plate_confidence'] < VisitorRecord::LOW_PLATE_CONFIDENCE || $data['plate_confidence'] > 1) {
            return 'OCR confidence is missing or below the review threshold. Confirm the text and identifier type.';
        }

        return 'Identifier type is unconfirmed. Inspect the images: confirm an actual plate or record a temporary identifier. Text confidence and a Registry match do not prove the type.';
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
