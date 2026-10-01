{{-- UI Phase 3: Mark lost / Disable / Enable for one tag. --}}
@php
    $actions = match ($tag->status) {
        'lost', 'disabled' => ['available' => $tag->status === 'lost' ? 'Mark found' : 'Enable'],
        default => ['lost' => 'Mark lost', 'disabled' => 'Disable'],
    };
@endphp

@foreach ($actions as $status => $label)
    <form method="POST" action="{{ route('registry.tags.status', $tag) }}"
          @if ($status !== 'available') data-confirm="{{ $label }} {{ $tag->label }}?{{ $tag->vehicle && $tag->status === 'assigned' ? ' It is the tag of '.$tag->vehicle->plate_number.'; scans of it will be flagged.' : ' Scans of it will raise an alert.' }}" @endif>
        @csrf
        <input type="hidden" name="status" value="{{ $status }}">
        <button type="submit" class="button {{ $status === 'available' ? 'button-secondary' : 'button-subtle-danger' }} button-sm">{{ $label }}</button>
    </form>
@endforeach
