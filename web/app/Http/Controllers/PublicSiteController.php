<?php

namespace App\Http\Controllers;

use App\AccountRequests\AccountRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public home page and "request a free account" form (only when dmarc.public_site.enabled). */
class PublicSiteController
{
    public function home(): View|RedirectResponse
    {
        if (! config('dmarc.public_site.enabled')) {
            return redirect('/app');
        }

        return view('public.home');
    }

    public function create(): View
    {
        $this->ensureEnabled();

        return view('public.request');
    }

    public function store(Request $request, AccountRequestService $service): RedirectResponse
    {
        $this->ensureEnabled();

        // Honeypot: real people never see or fill this field. Pretend it worked.
        if (filled($request->input('website'))) {
            return redirect()->route('public.requested');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'organisation' => ['required', 'string', 'max:255'],
            'domains' => ['required', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'volume' => ['accepted'],
        ], [
            'volume.accepted' => 'Please confirm your domains send no more than '.number_format(config('dmarc.public_site.free_monthly_emails')).' emails a month.',
        ]);

        $service->submit($data, $request->ip(), $request->userAgent());

        return redirect()->route('public.requested');
    }

    public function requested(): View
    {
        $this->ensureEnabled();

        return view('public.requested');
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('dmarc.public_site.enabled'), 404);
    }
}
