@extends('layouts.app')
@section('title', 'Accounts | PHILCST')
@section('content')
<x-page-header title="Accounts" />
<section class="panel">
    <h2>Create an individual account</h2>
    <p>Deliver the activation code privately to its owner. Accounts remain pending until their owner creates a personal password.</p>
    <form method="POST" action="{{ route('accounts.store') }}" class="stack-form">
        @csrf
        <div class="field"><label for="name">Name</label><input id="name" name="name" required maxlength="120" value="{{ old('name') }}"></div>
        <div class="field"><label for="email">Email (sign-in identity)</label><input id="email" name="email" type="email" required maxlength="255" value="{{ old('email') }}"></div>
        <div class="field"><label for="role">Role</label><select id="role" name="role"><option value="guard">Guard</option><option value="admin">Admin</option></select></div>
        <button type="submit" class="button button-primary">Create account and activation code</button>
    </form>
</section>
<section class="panel"><h2>Individual accounts</h2>
    @error('account')<p class="field-error">{{ $message }}</p>@enderror
    @foreach ($users as $account)
        <article class="setup-part">
            <h3>{{ $account->name }}</h3><p>{{ $account->email }} · {{ $account->role }} · {{ $account->account_status }}</p>
            @if ($account->account_status === 'pending')
                <form method="POST" action="{{ route('accounts.activation.replace', $account) }}" data-confirm="Replace the activation code for {{ $account->name }}? The old code will stop working immediately.">
                    @csrf<button class="button button-secondary">Replace unused activation code</button>
                </form>
            @else
                <form method="POST" action="{{ route('accounts.update', $account) }}" class="stack-form" data-confirm="Change permissions for {{ $account->name }}? Their sessions will be signed out.">
                    @csrf @method('PATCH')
                    <label>Role<select name="role"><option value="guard" @selected($account->role === 'guard')>Guard</option><option value="admin" @selected($account->role === 'admin')>Admin</option></select></label>
                    <label>Status<select name="account_status"><option value="active" @selected($account->account_status === 'active')>Active</option><option value="disabled" @selected($account->account_status === 'disabled')>Disabled</option></select></label>
                    <button class="button button-secondary">Save permissions</button>
                </form>
            @endif
        </article>
    @endforeach
    {{ $users->links() }}
</section>
@endsection
