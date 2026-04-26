<?php

namespace Database\Seeders;

use App\Models\ActiveSession;
use App\Models\VehicleEvent;
use Illuminate\Database\Seeder;

class ActiveSessionSeeder extends Seeder
{
    /**
     * Seed open and closed sessions that mirror the sample vehicle events.
     */
    public function run(): void
    {
        $matchedEntry = VehicleEvent::query()
            ->where('event_type', 'ENTRY')
            ->where('plate_text', 'ABC-1234')
            ->firstOrFail();

        $reviewEntry = VehicleEvent::query()
            ->where('event_type', 'ENTRY')
            ->where('plate_text', 'DEF-5678')
            ->firstOrFail();

        $openEntry = VehicleEvent::query()
            ->where('event_type', 'ENTRY')
            ->where('plate_text', 'GHI-9012')
            ->firstOrFail();

        $oldOpenEntry = VehicleEvent::query()
            ->where('event_type', 'ENTRY')
            ->where('plate_text', 'LMN-3456')
            ->firstOrFail();

        $sessions = [
            [
                'entry_event_id' => $matchedEntry->id,
                'plate_text' => $matchedEntry->plate_text,
                'vehicle_type' => $matchedEntry->vehicle_type,
                'vehicle_color' => $matchedEntry->vehicle_color,
                'entry_time' => $matchedEntry->event_time,
                'status' => 'closed',
            ],
            [
                'entry_event_id' => $reviewEntry->id,
                'plate_text' => $reviewEntry->plate_text,
                'vehicle_type' => $reviewEntry->vehicle_type,
                'vehicle_color' => $reviewEntry->vehicle_color,
                'entry_time' => $reviewEntry->event_time,
                'status' => 'open',
            ],
            [
                'entry_event_id' => $openEntry->id,
                'plate_text' => $openEntry->plate_text,
                'vehicle_type' => $openEntry->vehicle_type,
                'vehicle_color' => $openEntry->vehicle_color,
                'entry_time' => $openEntry->event_time,
                'status' => 'open',
            ],
            [
                'entry_event_id' => $oldOpenEntry->id,
                'plate_text' => $oldOpenEntry->plate_text,
                'vehicle_type' => $oldOpenEntry->vehicle_type,
                'vehicle_color' => $oldOpenEntry->vehicle_color,
                'entry_time' => $oldOpenEntry->event_time,
                'status' => 'open',
            ],
        ];

        foreach ($sessions as $session) {
            ActiveSession::query()->updateOrCreate(
                ['entry_event_id' => $session['entry_event_id']],
                $session
            );
        }
    }
}
