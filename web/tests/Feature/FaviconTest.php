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

    public function test_both_panels_use_the_favicon_as_brand_logo(): void
    {
        foreach (['/app/login' => config('app.name'), '/admin/login' => config('app.name').' · MSP Admin'] as $url => $name) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<img src="'.asset('favicon.svg').'"', false)
                ->assertSee($name);
        }
    }
}
