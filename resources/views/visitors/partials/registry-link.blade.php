{{-- Phase 6 (visitor model): the plate's Registry vehicle, or "Register this vehicle". --}}
@if ($profile->vehicle)
    <span class="table-subtext">In the Registry{{ $profile->registered_at ? ' since '.$profile->registered_at->format('M j, Y') : '' }}</span>
@elseif (auth()->user()?->isAdmin())
    <a href="{{ route('registry.index', ['tab' => 'vehicles', 'register_plate' => $profile->id]) }}" class="button button-secondary button-sm">Register this vehicle</a>
@else
    <span class="table-subtext">Not registered</span>
@endif
