@extends('public.layout')

@section('title', 'Request received · '.config('app.name'))

@section('content')
<section class="hero">
    <div class="wrap">
        <h1>Thanks, we've got your request.</h1>
        <p class="lead">We'll review it within {{ config('dmarc.public_site.review_hours') }} hours. Once it's approved you'll get an email invitation to set up your sign-in and add your domains.</p>
        <div class="actions"><a class="button secondary" href="{{ route('public.home') }}">Back to home</a></div>
    </div>
</section>
@endsection
