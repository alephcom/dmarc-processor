@extends('public.layout')

@php($limit = number_format(config('dmarc.public_site.free_monthly_emails')))
@php($repo = config('dmarc.public_site.repo_url'))
@php($hours = config('dmarc.public_site.review_hours'))

@section('content')
<section class="hero">
    <div class="wrap">
        <h1>Email security basics should be easy, and free.</h1>
        <p class="lead">
            Anyone can send email that pretends to come from your domain. DMARC, SPF and DKIM stop that,
            but setting them up and keeping an eye on them has been harder than it should be.
            We think these internet security basics should be readily available to everyone, so we run this service for free.
        </p>
        <div class="actions">
            <a class="button" href="{{ route('public.request') }}">Request a free account</a>
            <a class="button secondary" href="{{ url('/app/login') }}">Sign in</a>
        </div>
    </div>
</section>

<section>
    <div class="wrap">
        <h2>What you get</h2>
        <div class="grid">
            <div class="card">
                <h3>Guided setup</h3>
                <p class="muted">A step-by-step wizard checks your current DNS and gives you the exact DMARC, SPF and DKIM records to publish.</p>
            </div>
            <div class="card">
                <h3>See who sends as you</h3>
                <p class="muted">We collect the DMARC reports mailbox providers send every day and show which services send email using your domain, and whether it passes.</p>
            </div>
            <div class="card">
                <h3>Alerts and reports</h3>
                <p class="muted">Get told when something breaks or a new sender appears, plus regular digests and printable reports.</p>
            </div>
            <div class="card">
                <h3>A safe path to enforcement</h3>
                <p class="muted">We guide you from monitoring (<code>p=none</code>) to blocking spoofed mail (<code>p=reject</code>) once your legitimate senders all pass.</p>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="wrap">
        <h2>How it works</h2>
        <ol class="steps">
            <li><strong>Request an account.</strong> <span class="muted">Tell us who you are and which domains you'd like to protect. Anybody is welcome to ask.</span></li>
            <li><strong>We review it within {{ $hours }} hours.</strong> <span class="muted">A real person checks each request, to keep the service running well for everyone.</span></li>
            <li><strong>Accept your invitation.</strong> <span class="muted">You'll get an email to set up your sign-in, then the wizard walks you through adding your domains.</span></li>
        </ol>
    </div>
</section>

<section>
    <div class="wrap">
        <h2>Who it's for</h2>
        <div class="callout">
            <p>We'll gladly host anyone whose domains send <strong>up to {{ $limit }} emails a month</strong>: small businesses, charities, clubs, schools and individuals.</p>
            <p>If you send more than that, we suggest running your own copy. The software is free and open source; <a href="{{ $repo }}">follow the instructions on GitHub</a> to set it up on your own server.</p>
            <p>Please note that the free service <strong>doesn't include tech support</strong>. The setup wizard explains each step, and the documentation is <a href="{{ $repo }}">on GitHub</a>.</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('public.request') }}">Request a free account</a>
            <a class="button secondary" href="{{ $repo }}">Self-host from GitHub</a>
        </div>
    </div>
</section>
@endsection
