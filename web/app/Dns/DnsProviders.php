<?php

namespace App\Dns;

/**
 * Identifies the DNS host from NS records so the wizard can show the right instructions.
 */
class DnsProviders
{
    /** nameserver suffix => provider key */
    private const NS_SUFFIXES = [
        'ns.cloudflare.com' => 'cloudflare',
        'domaincontrol.com' => 'godaddy',
        'awsdns' => 'route53',
        'registrar-servers.com' => 'namecheap',
        'azure-dns.com' => 'azure',
        'azure-dns.net' => 'azure',
        'azure-dns.org' => 'azure',
        'azure-dns.info' => 'azure',
        'bdm.microsoftonline.com' => 'microsoft365',
        'googledomains.com' => 'squarespace',
        'squarespacedns.com' => 'squarespace',
        'ns.google.com' => 'google_cloud',
        'googlehosted.com' => 'google_cloud',
        '123-reg.co.uk' => '123reg',
        'ui-dns.com' => 'ionos',
        'ui-dns.de' => 'ionos',
        'ui-dns.org' => 'ionos',
        'ui-dns.biz' => 'ionos',
        'ovh.net' => 'ovh',
        'digitalocean.com' => 'digitalocean',
        'hetzner.com' => 'hetzner',
        'gandi.net' => 'gandi',
        'name.com' => 'namecom',
        'dnsimple.com' => 'dnsimple',
        'dnsmadeeasy.com' => 'dnsmadeeasy',
        'hover.com' => 'hover',
        'wixdns.net' => 'wix',
        'porkbun.com' => 'porkbun',
    ];

    /** @var array<string, array{name: string, steps: list<string>}> */
    public const INSTRUCTIONS = [
        'cloudflare' => ['name' => 'Cloudflare', 'steps' => [
            'Sign in to the Cloudflare dashboard and select the domain.',
            'Go to DNS → Records and click "Add record".',
            'Type: TXT. Name: {host}. Content: the Value shown above.',
            'Click Save. Changes are usually live within a minute.',
        ]],
        'godaddy' => ['name' => 'GoDaddy', 'steps' => [
            'Sign in to GoDaddy, open My Products, and choose DNS next to the domain.',
            'Click "Add New Record" and choose type TXT.',
            'Name: {host}. Value: the Value shown above. TTL: 1 hour.',
            'Save. GoDaddy can take up to an hour to publish changes.',
        ]],
        'route53' => ['name' => 'Amazon Route 53', 'steps' => [
            'Open the Route 53 console → Hosted zones and select the domain.',
            'Click "Create record". Record name: {host}. Record type: TXT.',
            'Value: the Value shown above, wrapped in double quotes.',
            'Click "Create records".',
        ]],
        'namecheap' => ['name' => 'Namecheap', 'steps' => [
            'Sign in, go to Domain List and click Manage next to the domain.',
            'Open the Advanced DNS tab and click "Add New Record".',
            'Type: TXT Record. Host: {host}. Value: the Value shown above.',
            'Click the green tick to save.',
        ]],
        'microsoft365' => ['name' => 'Microsoft 365 DNS', 'steps' => [
            'In the Microsoft 365 admin center, go to Settings → Domains and select the domain.',
            'Open the DNS records tab and click "Add record".',
            'Type: TXT. TXT name: {host}. TXT value: the Value shown above.',
            'Save.',
        ]],
        'azure' => ['name' => 'Azure DNS', 'steps' => [
            'In the Azure portal, open DNS zones and select the domain.',
            'Click "+ Record set". Name: {host}. Type: TXT.',
            'Value: the Value shown above. Click OK.',
        ]],
        'squarespace' => ['name' => 'Squarespace Domains', 'steps' => [
            'Open the Squarespace Domains dashboard and select the domain.',
            'Go to DNS → DNS Settings → Custom records → Add record.',
            'Type: TXT. Host: {host}. Data: the Value shown above.',
            'Save.',
        ]],
        '123reg' => ['name' => '123 Reg', 'steps' => [
            'Sign in and go to Manage domain → Manage DNS (Advanced DNS).',
            'Add a new entry: Hostname {host}, Type TXT/SPF, Destination: the Value shown above.',
            'Click Add.',
        ]],
        'ionos' => ['name' => 'IONOS', 'steps' => [
            'Sign in and go to Domains & SSL, then the gear icon → DNS.',
            'Click "Add record" and choose TXT.',
            'Host name: {host}. Value: the Value shown above. Save.',
        ]],
    ];

    public const GENERIC = ['name' => 'your DNS provider', 'steps' => [
        'Sign in to wherever the DNS for this domain is managed (often your domain registrar or web host).',
        'Add a new TXT record.',
        'Host/Name: {host} (some providers want the full name: {fqdn}).',
        'Value/Content: the Value shown above. Leave TTL at the default.',
        'If a DMARC TXT record already exists at that name, edit it instead of adding a second one.',
    ]];

    public function __construct(private readonly Resolver $dns) {}

    public function detect(string $domain): ?string
    {
        try {
            $ns = $this->dns->ns($domain);
        } catch (DnsLookupException) {
            return null;
        }
        foreach ($ns as $host) {
            foreach (self::NS_SUFFIXES as $suffix => $key) {
                if (str_contains($host, $suffix)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** @return array{name: string, steps: list<string>} */
    public static function instructions(?string $provider, string $host, string $fqdn): array
    {
        $i = self::INSTRUCTIONS[$provider ?? ''] ?? self::GENERIC;
        $i['steps'] = array_map(fn ($s) => strtr($s, ['{host}' => $host, '{fqdn}' => $fqdn]), $i['steps']);

        return $i;
    }
}
