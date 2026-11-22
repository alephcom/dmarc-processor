<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaviconTest extends TestCase
{
    use RefreshDatabase;

    public function test_favicon_files_exist(): void
    {
        foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png'] as $file) {
            $this->assertGreaterThan(0, filesize(public_path($file)), $file);
        }
    }

    public function test_both_panel_login_pages_link_the_favicon(): void
    {
        foreach (['/app/login', '/admin/login'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('favicon.svg', false)
                ->assertSee('favicon.ico', false)
                ->assertSee('apple-touch-icon.png', false);
        }
    }
}
