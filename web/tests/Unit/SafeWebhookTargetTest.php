<?php

namespace Tests\Unit;

use App\Alerts\Delivery\SafeWebhookTarget;
use App\Dns\ArrayResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeWebhookTargetTest extends TestCase
{
    public static function blockedIps(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '10.1.2.3', '172.16.5.4', '192.168.1.1', '169.254.169.254', '0.0.0.0',
            '100.64.0.1', '198.18.0.1', '224.0.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:10.0.0.1', '64:ff9b::a00:1',
        ]);
    }

    #[DataProvider('blockedIps')]
    public function test_blocks_non_public_addresses(string $ip): void
    {
        $this->assertFalse(SafeWebhookTarget::isPublic($ip), $ip);
    }

    public function test_allows_public_addresses(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(SafeWebhookTarget::isPublic($ip), $ip);
        }
    }

    public function test_resolves_and_pins_public_host(): void
    {
        $dns = (new ArrayResolver)->set('hooks.example.com', 'A', ['93.184.216.34']);
        $t = (new SafeWebhookTarget($dns))->resolve('https://hooks.example.com:8443/x?y=1');
        $this->assertSame(['url' => 'https://hooks.example.com:8443/x?y=1', 'host' => 'hooks.example.com', 'port' => 8443, 'ip' => '93.184.216.34'], $t);
    }

    public static function rejectedUrls(): array
    {
        return [
            'http' => ['http://hooks.example.com/x', 'https://'],
            'internal service name' => ['https://web:8080/internal/ingest', "Can't resolve"],
            'resolves to private' => ['https://evil.example.com/', 'public internet'],
            'one private among many' => ['https://mixed.example.com/', 'public internet'],
            'ip literal' => ['https://169.254.169.254/latest/meta-data', 'public internet'],
            'credentials' => ['https://u:p@hooks.example.com/', 'credentials'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_rejects_unsafe_urls(string $url, string $message): void
    {
        $dns = (new ArrayResolver)
            ->set('hooks.example.com', 'A', ['93.184.216.34'])
            ->set('evil.example.com', 'A', ['10.0.0.5'])
            ->set('mixed.example.com', 'A', ['93.184.216.34', '127.0.0.1']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new SafeWebhookTarget($dns))->resolve($url);
    }
}
