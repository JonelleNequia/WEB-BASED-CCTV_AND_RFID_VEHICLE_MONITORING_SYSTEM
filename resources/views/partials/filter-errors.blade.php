@if (session('invalid_list_filters'))
    <div class="panel" role="alert" data-list-filter-errors>
        <strong>Some filters could not be applied. Your other selections have been kept.</strong>
        <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif
