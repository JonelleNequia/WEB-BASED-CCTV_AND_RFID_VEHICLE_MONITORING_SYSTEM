{{-- B3: put one Advanced section back to its defaults (asks first). --}}
<form method="POST" action="{{ route('settings.restore-defaults') }}" data-confirm="Put every setting on this page back to its default value?">
    @csrf
    <input type="hidden" name="section" value="{{ $section }}">
    <button type="submit" class="button button-secondary button-sm">Restore defaults</button>
</form>
