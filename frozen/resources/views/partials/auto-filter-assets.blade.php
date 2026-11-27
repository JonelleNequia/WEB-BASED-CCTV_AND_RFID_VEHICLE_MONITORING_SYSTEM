@push('styles')
    <link rel="stylesheet" href="{{ asset('css/auto-filters.css') }}?v={{ filemtime(public_path('css/auto-filters.css')) }}">
@endpush
@push('scripts')
    <script src="{{ asset('js/auto-filters.js') }}?v={{ filemtime(public_path('js/auto-filters.js')) }}"></script>
@endpush
