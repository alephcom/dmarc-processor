<?php

namespace App\Http\Middleware;

use App\Support\IngestSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from smtp-receiver. See smtp-receiver/internal/signer for the scheme.
 */
class VerifyIngestSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('dmarc.ingest_hmac_secret');
        if (strlen($secret) < 32) {
            abort(503, 'Ingest secret not configured');
        }

        $ts = $request->header('X-Timestamp');
        $sig = (string) $request->header('X-Signature');
        if (! is_string($ts) || ! ctype_digit($ts) || abs(time() - (int) $ts) > config('dmarc.ingest_max_skew')) {
            abort(401, 'Stale or missing timestamp');
        }

        $expected = IngestSignature::sign($secret, (int) $ts, $request->method(), '/'.ltrim($request->path(), '/'), $request->getContent());
        if (! hash_equals($expected, $sig)) {
            abort(401, 'Bad signature');
        }

        return $next($request);
    }
}
