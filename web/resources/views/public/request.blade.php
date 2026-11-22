@extends('public.layout')

@section('title', 'Request a free account · '.config('app.name'))

@php($limit = number_format(config('dmarc.public_site.free_monthly_emails')))

@section('content')
<section>
    <div class="wrap">
        <h1>Request a free account</h1>
        <p class="lead">Anybody is welcome to ask. We review every request within {{ config('dmarc.public_site.review_hours') }} hours and email you an invitation to get started.</p>

        <form class="request" method="post" action="{{ route('public.request.store') }}">
            @csrf
            <div class="hp" aria-hidden="true">
                <label for="website">Leave this empty</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <label for="name">Your name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name">
            @error('name')<div class="error">{{ $message }}</div>@enderror

            <label for="email">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email">
            <div class="hint">Your invitation will be sent here.</div>
            @error('email')<div class="error">{{ $message }}</div>@enderror

            <label for="organisation">Organisation</label>
            <input type="text" id="organisation" name="organisation" value="{{ old('organisation') }}" required maxlength="255" autocomplete="organization">
            <div class="hint">Your company, charity, club or just your own name.</div>
            @error('organisation')<div class="error">{{ $message }}</div>@enderror

            <label for="domains">Domains to protect</label>
            <textarea id="domains" name="domains" required maxlength="2000" placeholder="example.com">{{ old('domains') }}</textarea>
            <div class="hint">One per line.</div>
            @error('domains')<div class="error">{{ $message }}</div>@enderror

            <label for="message">Anything else? <span class="muted">(optional)</span></label>
            <textarea id="message" name="message" maxlength="2000">{{ old('message') }}</textarea>
            @error('message')<div class="error">{{ $message }}</div>@enderror

            <label class="check"><input type="checkbox" name="volume" value="1" @checked(old('volume'))> <span>My domains send no more than {{ $limit }} emails a month in total.</span></label>
            <div class="hint">Sending more? We suggest <a href="{{ config('dmarc.public_site.repo_url') }}">running your own copy</a> instead.</div>
            @error('volume')<div class="error">{{ $message }}</div>@enderror

            <div class="actions"><button class="button" type="submit">Send request</button></div>
        </form>
    </div>
</section>
@endsection
