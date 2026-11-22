<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    private function invite(string $email = 'new@acme.test', string $role = 'admin'): string
    {
        [, $token] = Invitation::issue($this->tenant, $email, $role, null);

        return $token;
    }

    public function test_new_user_creates_account_and_joins(): void
    {
        $token = $this->invite();
        $this->get("/invitations/{$token}")->assertOk()->assertSee('Join Acme')->assertSee('Create account and join');

        $this->post("/invitations/{$token}", ['name' => 'New Person', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->post("/invitations/{$token}", ['name' => 'New Person', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
            ->assertRedirect('/app/acme');

        $user = User::firstWhere('email', 'new@acme.test');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('admin', Membership::where('user_id', $user->id)->value('role'));
        $this->assertNotNull(Invitation::sole()->accepted_at);
        $this->assertTrue(AuditLog::where('action', 'invitation.accepted')->exists());
    }

    public function test_link_is_single_use_and_expires(): void
    {
        $token = $this->invite();
        $this->post("/invitations/{$token}", ['name' => 'N', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1']);
        auth()->logout();
        $this->get("/invitations/{$token}")->assertSee('This invitation isn\'t valid', false);

        $token2 = $this->invite('other@acme.test');
        $this->travel(8)->days();
        $this->get("/invitations/{$token2}")->assertSee('This invitation isn\'t valid', false);
        $this->post("/invitations/{$token2}", ['name' => 'N', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
            ->assertSee('This invitation isn\'t valid', false);
        $this->assertNull(User::firstWhere('email', 'other@acme.test'));
    }

    public function test_existing_user_must_sign_in_then_accepts(): void
    {
        $user = User::factory()->create(['email' => 'existing@acme.test']);
        $token = $this->invite('existing@acme.test', 'viewer');

        $this->get("/invitations/{$token}")->assertSee('Sign in');
        // A guest can't take over the existing account by posting a password.
        $this->post("/invitations/{$token}", ['name' => 'Attacker', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
            ->assertRedirect("/invitations/{$token}");
        $this->assertGuest();
        $this->assertSame(0, Membership::count());

        $this->actingAs($user)->post("/invitations/{$token}")->assertRedirect('/app/acme');
        $this->assertSame('viewer', Membership::where('user_id', $user->id)->value('role'));
    }

    public function test_signed_in_as_someone_else_cannot_accept(): void
    {
        $token = $this->invite('intended@acme.test');
        $other = User::factory()->create(['email' => 'other@acme.test']);
        $this->actingAs($other)->get("/invitations/{$token}")->assertSee('but this invitation is for');
        $this->actingAs($other)->post("/invitations/{$token}")->assertSessionHasErrors('invitation');
        $this->assertSame(0, Membership::count());
    }

    public function test_unknown_token(): void
    {
        $this->get('/invitations/'.str_repeat('x', 48))->assertSee('This invitation isn\'t valid', false);
    }

    public function test_token_is_not_stored(): void
    {
        $token = $this->invite();
        $this->assertSame(0, Invitation::where('token_hash', $token)->count());
        $this->assertSame(hash('sha256', $token), Invitation::sole()->token_hash);
    }
}
