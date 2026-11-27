@extends('layouts.app')
@section('title', 'Deliver activation code | PHILCST')
@section('content')
<x-page-header title="Deliver activation code privately" />
<section class="panel">
    <h2>{{ $account->name }} · {{ $account->email }} · {{ $account->role }}</h2>
    <p>This code is shown only in this response. Deliver it personally. It expires in 24 hours and allows five attempts. Do not put it into a URL or a shared message.</p>
    <label for="activation_code">Activation code</label><input id="activation_code" type="text" value="{{ $code }}" readonly autocomplete="off" spellcheck="false">
    <p>The owner opens <strong>/activate</strong> and enters their assigned email, this code and a personal password. Replacing an unused code invalidates the previous one.</p>
    <a href="{{ route('accounts.index') }}" class="button button-primary">Done — close code display</a>
</section>
@endsection
