{{-- ROI work, Phase 2: "Set IN" / "Set OUT" for a record the camera could not tell (public/js/activity-logs.js builds the same form for live rows). --}}
@props(['url'])
<form method="POST" action="{{ $url }}" class="set-direction" data-set-direction>
    @csrf
    @method('PATCH')
    <button type="submit" name="direction" value="IN" class="button button-secondary button-sm">Set IN</button>
    <button type="submit" name="direction" value="OUT" class="button button-secondary button-sm">Set OUT</button>
</form>
