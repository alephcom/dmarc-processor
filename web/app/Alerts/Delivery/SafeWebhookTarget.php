<?php

namespace App\Alerts\Delivery;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;
use InvalidArgumentException;

/**
 * Validates a tenant-supplied webhook URL and pins it to a public IP address.
 *
 * Tenants control the URL, so without this a webhook could reach internal services
 * (the app's /internal API, the database, cloud metadata endpoints). We require HTTPS,
 * resolve the host ourselves, reject private/reserved addresses, and connect to the
 * address we checked (no second DNS lookup, so DNS rebinding can't swap it).
 */
class SafeWebhookTarget
{
    public function __construct(private readonly Resolver $dns) {}

    /**
     * @return array{url: string, host: string, port: int, ip: string}
     *
     * @throws InvalidArgumentException with a message safe to show the user
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Webhook URLs must start with https://');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URLs must not contain credentials; use the signing secret instead.');
        }
        $host = strtolower($parts['host']);
        $port = (int) ($parts['port'] ?? 443);

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            $ips = [$literal];
        } else {
            try {
                $ips = $this->dns->addresses($host);
            } catch (DnsLookupException) {
                $ips = [];
            }
            if ($ips === []) {
                throw new InvalidArgumentException("Can't resolve {$host}.");
            }
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new InvalidArgumentException('Webhook URLs must point to a public internet address.');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Ranges PHP's flags don't cover: CGNAT, IPv4-mapped/NAT64 forms of private space, multicast.
        foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '::ffff:0:0/96', '64:ff9b::/96', 'ff00::/8'] as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $rem = (int) $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }

        return $rem === 0 || ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0;
    }
}
