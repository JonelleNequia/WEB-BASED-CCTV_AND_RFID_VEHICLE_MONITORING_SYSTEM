<?php

namespace App\Services;

use App\Models\VisitorRecord;
use App\Support\PlateNumber;
use Illuminate\Database\Eloquent\Builder;

/** The Guests records list and its reports share the same occurrence query. */
class GuestRecordQuery
{
    public function query(array $filters = []): Builder
    {
        $status = $filters['status'] ?? VisitorRecord::STATUS_ACTIVE;

        return VisitorRecord::query()
            ->with(['plateProfile', 'corrector', 'vehicle'])
            ->when(($filters['record_filter'] ?? '') === 'registered', fn (Builder $query) => $query->registeredAtOccurrence())
            ->when(($filters['record_filter'] ?? '') === 'unregistered', fn (Builder $query) => $query->unregisteredAtOccurrence())
            ->when(($filters['record_filter'] ?? '') === 'manual', fn (Builder $query) => $query->where('source', VisitorRecord::SOURCE_MANUAL))
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when(filled($filters['gate'] ?? null), fn (Builder $query) => $query->where('gate', $filters['gate']))
            ->when(filled($filters['plate_status'] ?? null), fn (Builder $query) => $query->where('plate_status', $filters['plate_status']))
            ->when(filled($filters['q'] ?? null), function (Builder $query) use ($filters): void {
                PlateNumber::search($query, ['plate_number', 'ocr_plate_number'], $filters['q']);
            })
            ->latest('seen_at')
            ->latest('id');
    }
}
