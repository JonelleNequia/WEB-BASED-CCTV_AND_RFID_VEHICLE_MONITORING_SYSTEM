{{--
    UI Phase 1: table card with toolbar (search, filters, export), empty state
    and pagination. Pass <thead>/<tbody> as the slot.
--}}
@props([
    'title' => null,
    'paginator' => null,
    'empty' => false,
    'emptyTitle' => 'No records yet',
    'emptyText' => null,
])

<section {{ $attributes->class('data-table') }}>
    @if ($title || isset($toolbar))
        <div class="data-table-toolbar">
            @if ($title)
                <h2 class="data-table-title">{{ $title }}</h2>
            @endif
            @isset($toolbar)
                <div class="data-table-tools">{{ $toolbar }}</div>
            @endisset
        </div>
    @endif

    @isset($filters)
        <div class="data-table-filters">{{ $filters }}</div>
    @endisset

    @if ($empty)
        <x-empty-state :title="$emptyTitle" :text="$emptyText">{{ $emptyAction ?? '' }}</x-empty-state>
    @else
        <div class="table-responsive">
            <table>
                {{ $slot }}
            </table>
        </div>
    @endif

    @if ($paginator && ! $empty)
        @include('layouts.partials.pagination', ['paginator' => $paginator])
    @endif
</section>
