@props(['name' => 'gate', 'id' => 'gate', 'value' => '', 'options' => null, 'all' => 'All gates'])
@php
    $value = is_string($value) ? $value : '';
    $code = \App\Models\Gate::resolveCode($value, historical: true);
    $archived = $code ? \App\Models\Gate::query()->where('code', $code)->whereNotNull('archived_at')->first() : null;
    $options ??= \App\Models\Gate::filterOptions();
@endphp
<div class="field" data-auto-update="gate-filter-{{ $name }}" data-gate-filter>
    @if ($archived)
        <span class="field-label">Gate</span>
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        <span class="field-help" role="status" data-archived-gate-context>Historical filter: {{ $archived->name }} (archived). Past records only.</span>
        <a href="{{ request()->fullUrlWithoutQuery([$name, 'page']) }}" class="button button-secondary button-sm">Change gate</a>
    @else
        <label for="{{ $id }}">Gate</label>
        <select id="{{ $id }}" name="{{ $name }}">
            <option value="">{{ $all }}</option>
            @foreach ($options as $code => $label)
                <option value="{{ $code }}" @selected($value === $code)>{{ $label }}</option>
            @endforeach
        </select>
    @endif
</div>
