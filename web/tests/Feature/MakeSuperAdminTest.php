<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_super_admin(): void
    {
        $this->artisan('dmarc:make-super-admin', ['email' => 'ops@msp.test', '--name' => 'Ops'])
            ->expectsQuestion('Password (min 12 characters)', 'correct-horse-battery')
            ->assertSuccessful();

        $this->assertTrue(User::firstWhere('email', 'ops@msp.test')->is_super_admin);
    }

    public function test_promotes_existing_user(): void
    {
        $u = User::factory()->create();
        $this->artisan('dmarc:make-super-admin', ['email' => $u->email])->assertSuccessful();
        $this->assertTrue($u->fresh()->is_super_admin);
    }
}
