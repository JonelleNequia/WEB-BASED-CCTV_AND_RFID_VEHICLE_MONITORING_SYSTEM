@extends('layouts.app')
@section('title', 'Check filters | PHILCST Vehicle Monitoring')
@section('content')
    <x-page-header title="Check the report filters" />
    <section class="panel" role="alert">
        <p>The report was not generated. Correct these filters and try again.</p>
        <ul>@foreach ($messages as $message)<li>{{ $message }}</li>@endforeach</ul>
        <a href="{{ $backUrl }}" class="button button-secondary">Return to filters</a>
    </section>
@endsection
