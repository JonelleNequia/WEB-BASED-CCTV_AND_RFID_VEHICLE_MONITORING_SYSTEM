@props(['vehicle'])
@php($active = $vehicle->status === 'active')
<span class="registration-status-indicator">
    <span @class(['registration-status-dot', 'is-active' => $active, 'is-inactive' => !$active]) aria-hidden="true"></span>
    <span class="sr-only">{{ $active ? 'Active' : 'Inactive' }}</span>
</span>
