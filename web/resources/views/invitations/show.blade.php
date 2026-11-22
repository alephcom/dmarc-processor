@extends('invitations.layout')
@section('title', 'Join '.$inv->tenant->name)
@section('content')
    <h1>Join {{ $inv->tenant->name }}</h1>
    <p>You've been invited as {{ $inv->role === 'viewer' ? 'a viewer' : 'an '.$inv->role }}.</p>

    @error('invitation')<p class="error">{{ $message }}</p>@enderror

    @if ($mismatch)
        <p>You're signed in as <strong>{{ $user->email }}</strong>, but this invitation is for <strong>{{ $inv->email }}</strong>. Sign out, then open the link again.</p>
    @elseif ($user)
        <form method="post" action="{{ route('invitations.accept', $token) }}">
            @csrf
            <button type="submit">Accept and continue</button>
        </form>
    @elseif ($needsLogin)
        <p>You already have an account for {{ $inv->email }}. Sign in and you'll come straight back here.</p>
        <a class="button" href="{{ url('/app/login') }}">Sign in</a>
    @else
        <form method="post" action="{{ route('invitations.accept', $token) }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" value="{{ $inv->email }}" readonly>
            <label for="name">Your name</label>
            <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
            @error('name')<div class="error">{{ $message }}</div>@enderror
            <label for="password">Choose a password</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
            @error('password')<div class="error">{{ $message }}</div>@enderror
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            <button type="submit">Create account and join</button>
        </form>
    @endif
@endsection
