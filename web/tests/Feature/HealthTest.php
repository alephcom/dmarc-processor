<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_root_redirects_to_app_when_public_site_is_off(): void
    {
        $this->get('/')->assertRedirect('/app');
    }

    public function test_internal_routes_require_signature(): void
    {
        $this->postJson('/internal/ingest', [])->assertServiceUnavailable(); // no secret configured

        config(['dmarc.ingest_hmac_secret' => str_repeat('s', 32)]);
        $this->postJson('/internal/ingest', [])->assertUnauthorized();
    }
}
