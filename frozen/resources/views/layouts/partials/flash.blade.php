{{--
    UI Phase 1: session messages become toasts (public/js/ui.js shows them).
    Field errors still appear next to each input.
--}}
@php
    $toasts = [];

    if (session('status')) {
        $toasts[] = ['type' => 'success', 'message' => (string) session('status')];
    }

    if (session('error')) {
        $toasts[] = ['type' => 'error', 'message' => (string) session('error')];
    }

    if ($errors->any()) {
        $toasts[] = [
            'type' => 'error',
            'title' => 'Please check the form',
            'message' => $errors->count() > 1
                ? $errors->first().' (+'.($errors->count() - 1).' more)'
                : $errors->first(),
        ];
    }
@endphp

<div class="toast-stack" data-toast-stack aria-live="polite" aria-atomic="false"></div>
<script type="application/json" data-initial-toasts>{!! json_encode($toasts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
