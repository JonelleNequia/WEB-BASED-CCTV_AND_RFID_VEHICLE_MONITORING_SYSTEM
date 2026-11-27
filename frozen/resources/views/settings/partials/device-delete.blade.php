{{-- Delete device work: one confirmation for "Delete device" (public/js/device-delete.js). --}}
@once
    <x-modal id="device-delete" title="Delete device" size="sm">
        <div class="device-delete" data-device-delete-body aria-live="polite"></div>
    </x-modal>
    @push('scripts')
        <script src="{{ asset('js/device-delete.js') }}"></script>
    @endpush
@endonce
