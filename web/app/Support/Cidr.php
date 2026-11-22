<?php

namespace App\Support;

final class Cidr
{
    /** Whether $ip is inside $cidr ("192.0.2.0/24", "2001:db8::/32", or a bare address). */
    public static function contains(string $cidr, string $ip): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null || $bits === '' ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }

        return $rem === 0 || ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0;
    }

    /** Prefix length of a CIDR (bare address = full length); used to prefer the most specific match. */
    public static function prefix(string $cidr): int
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $len = str_contains((string) $net, ':') ? 128 : 32;

        return $bits === null || $bits === '' ? $len : (int) $bits;
    }

    public static function isIpv6(string $cidr): bool
    {
        return str_contains(explode('/', $cidr, 2)[0], ':');
    }
}
