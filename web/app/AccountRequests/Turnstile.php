<?php

namespace App\AccountRequests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Cloudflare Turnstile check for the public account-request form. */
class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const ACTION = 'account-request';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    /** Fails closed: if Cloudflare can't be reached, the submission is refused and the visitor can retry. */
    public function verify(?string $token, ?string $ip): bool
    {
        if (blank($token) || strlen($token) > 2048) {
            return false;
        }
        try {
            $res = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile verification failed', ['error' => $e->getMessage()]);

            return false;
        }

        // The token must be for our widget's action, so a token solved on another page can't be reused here.
        return $res->ok() && $res->json('success') === true && $res->json('action') === self::ACTION;
    }
}
