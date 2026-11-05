<?php

namespace App\Http\Controllers;

use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Support\OccurrenceMedia;
use Illuminate\Support\Facades\Storage;

class OccurrenceMediaController extends Controller
{
    public function __invoke(string $file)
    {
        $path = OccurrenceMedia::PREFIX.$file;
        abort_unless(OccurrenceMedia::privateFile($path) && OccurrenceMedia::exists($path), 404);
        // A staged/uncommitted file cannot be viewed merely by guessing its UUID.
        $referenced = VehicleCrossing::where('snapshot_path', $path)->exists()
            || VisitorRecord::where('snapshot_path', $path)->orWhere('plate_image_path', $path)->exists()
            || \App\Models\GuestVehicleObservation::where('snapshot_path', $path)->exists()
            || VehicleEvent::where('vehicle_image_path', $path)->orWhere('plate_image_path', $path)->exists();
        abort_unless($referenced, 404);

        return response()->file(Storage::disk('occurrences')->path($file), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
