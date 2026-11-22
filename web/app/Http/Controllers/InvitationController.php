<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Team\TeamRuleException;
use App\Team\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Accepting an invitation:
 *  - signed in with the invited address → confirm and join
 *  - account exists but not signed in    → sign in, then come back here
 *  - no account                          → choose a name and password, then join
 */
class InvitationController
{
    public function show(string $token): View|RedirectResponse
    {
        $inv = Invitation::findByToken($token);
        if (! $inv || ! $inv->isUsable()) {
            return view('invitations.invalid');
        }
        $user = Auth::user();
        $existing = User::where('email', $inv->email)->exists();

        if (! $user && $existing) {
            session(['url.intended' => route('invitations.show', $token)]);
        }

        return view('invitations.show', [
            'inv' => $inv,
            'token' => $token,
            'user' => $user,
            'mismatch' => $user && strtolower($user->email) !== $inv->email,
            'needsLogin' => ! $user && $existing,
        ]);
    }

    public function accept(Request $request, string $token, TeamService $team): RedirectResponse|View
    {
        $inv = Invitation::findByToken($token);
        if (! $inv || ! $inv->isUsable()) {
            return view('invitations.invalid');
        }

        $user = Auth::user();
        if (! $user) {
            if (User::where('email', $inv->email)->exists()) {
                return redirect()->route('invitations.show', $token);
            }
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(12)],
            ]);
            $user = User::create(['name' => $data['name'], 'email' => $inv->email, 'password' => $data['password']]);
            // Following the emailed link proves they control the address.
            $user->forceFill(['email_verified_at' => now()])->save();
            Auth::login($user);
            $request->session()->regenerate();
        }

        try {
            $team->accept($inv, $user);
        } catch (TeamRuleException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return redirect('/app/'.$inv->tenant->slug);
    }
}
