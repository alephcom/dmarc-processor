@php($admin = \App\Team\Impersonation::impersonator())
@if ($admin)
    {{-- Inline styles: this must render regardless of theme or panel CSS. --}}
    <div role="status" style="display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;padding:8px 16px;background:#b45309;color:#fff;font-size:14px;font-weight:500;">
        <span>You ({{ $admin->email }}) are signed in as <strong>{{ auth()->user()->email }}</strong>. Everything you do is recorded under both names.</span>
        <form method="post" action="{{ route('impersonation.leave') }}" style="margin:0;">
            @csrf
            <button type="submit" style="background:#fff;color:#7c2d12;border:0;border-radius:6px;padding:4px 10px;font:inherit;font-weight:600;cursor:pointer;">Return to MSP admin</button>
        </form>
    </div>
@endif
