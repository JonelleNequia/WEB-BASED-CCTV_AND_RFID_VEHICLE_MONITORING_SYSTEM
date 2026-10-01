<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTab;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UI Phase 2: Registry page (Vehicles | RFID Tags). Guest Passes removed in Phase 0.
 * Each tab keeps its own data in VehicleRegistryController.
 */
class RegistryController extends Controller
{
    use ResolvesTab;

    public const TABS = [
        'vehicles' => 'Vehicles',
        'tags' => 'RFID Tags',
    ];

    public function index(Request $request, VehicleRegistryController $registry): View
    {
        return match ($this->resolveTab($request, self::TABS)) {
            'tags' => app()->call([$registry, 'rfidInventory']),
            default => app()->call([$registry, 'index']),
        };
    }
}
