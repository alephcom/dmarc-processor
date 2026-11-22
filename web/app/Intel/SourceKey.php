<?php

namespace App\Intel;

use App\Models\IpIntel;

/**
 * How report sources are grouped: by known sender, else by network (ASN), else by single IP.
 */
final class SourceKey
{
    public static function for(string $ip, ?IpIntel $intel): string
    {
        return match (true) {
            $intel?->known_sender_id !== null => 'sender:'.$intel->known_sender_id,
            $intel?->asn !== null => 'asn:'.$intel->asn,
            default => 'ip:'.$ip,
        };
    }

    public static function label(string $key, ?IpIntel $intel): string
    {
        [$type, $value] = explode(':', $key, 2) + [1 => ''];

        return match ($type) {
            'sender' => $intel?->knownSender?->name ?? 'Known sender #'.$value,
            'asn' => trim('AS'.$value.' '.($intel?->as_org ?? '')),
            default => $intel?->rdns ? "{$value} ({$intel->rdns})" : $value,
        };
    }
}
