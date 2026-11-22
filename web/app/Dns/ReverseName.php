<?php

namespace App\Dns;

final class ReverseName
{
    /** in-addr.arpa / ip6.arpa name for an IP, or null if it isn't one. */
    public static function for(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 4) {
            return implode('.', array_reverse(explode('.', inet_ntop($bin)))).'.in-addr.arpa';
        }

        return implode('.', str_split(strrev(bin2hex($bin)))).'.ip6.arpa';
    }
}
