<?php

namespace Tests\Unit;

use App\Filament\Support\InitialsAvatarProvider;
use App\Models\Tenant;
use Tests\TestCase;

class InitialsAvatarProviderTest extends TestCase
{
    public function test_generates_local_data_uri_without_external_calls(): void
    {
        $url = (new InitialsAvatarProvider)->get(new Tenant(['name' => 'Acme <Widgets> Ltd']));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>AL</text>', $svg);
        $this->assertStringContainsString('>AD</text>', base64_decode(substr((new InitialsAvatarProvider)->get(new Tenant(['name' => 'Acme Widgets (demo)'])), 26)));
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg));
    }
}
