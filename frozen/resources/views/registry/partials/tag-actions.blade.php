{{-- UI Phase 3: Mark lost / Disable / Enable for one tag. --}}
@php
    $actions = \App\Models\RfidTag::isUnavailableUid($tag->uid) ? [] : match ($tag->status) {
        'lost' => [],
        'disabled' => ['available' => 'Enable'],
        default => ['lost' => 'Mark lost', 'disabled' => 'Disable'],
    };
@endphp

@foreach ($actions as $status => $label)
    <form method="POST" action="{{ route('registry.tags.status', $tag) }}"
          @if ($status !== 'available') data-confirm="{{ $label }} {{ $tag->label }}?{{ $status === 'lost' ? ' This UID becomes permanently Lost / Unavailable. Future reads are ignored. The vehicle and historical records are retained.' : ' Scans of this disabled tag remain flagged.' }}" @endif>
        @csrf
        <input type="hidden" name="status" value="{{ $status }}">
        <button type="submit" class="button {{ $status === 'available' ? 'button-secondary' : 'button-subtle-danger' }} button-sm">{{ $label }}</button>
    </form>
@endforeach
