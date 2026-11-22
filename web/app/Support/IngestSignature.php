<?php

namespace App\Support;

final class IngestSignature
{
    /** Must match smtp-receiver/internal/signer.Sign exactly. */
    public static function sign(string $secret, int $ts, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', $ts."\n".strtoupper($method)."\n".$path."\n".hash('sha256', $body), $secret);
    }
}
