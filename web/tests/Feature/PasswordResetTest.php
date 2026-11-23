<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_pages_offer_password_reset(): void
    {
        $this->get('/app/login')->assertOk()->assertSee('/app/password-reset/request', false);
        $this->get('/admin/login')->assertOk()->assertSee('/admin/password-reset/request', false);
    }

    public function test_user_can_reset_password_by_email(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create(['email' => 'pat@example.com']);
        $user->tenants()->attach(Tenant::factory()->create(), ['role' => User::ROLE_VIEWER]);

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'pat@example.com'])->call('request');
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $token = Password::broker()->createToken($user);
        Livewire::test(ResetPassword::class, ['email' => 'pat@example.com', 'token' => $token])
            ->fillForm(['password' => 'short', 'passwordConfirmation' => 'short'])
            ->call('resetPassword')
            ->assertHasFormErrors(['password']);
        Livewire::test(ResetPassword::class, ['email' => 'pat@example.com', 'token' => $token])
            ->fillForm(['password' => 'a-much-longer-password', 'passwordConfirmation' => 'a-much-longer-password'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-much-longer-password', $user->fresh()->password));
        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset')->count());
    }

    public function test_no_link_for_unknown_email_or_panel_without_access(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'customer@example.com'])
            ->tenants()->attach(Tenant::factory()->create(), ['role' => User::ROLE_ADMIN]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'nobody@example.com'])->call('request');
        // A customer can't get an MSP admin reset link.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'customer@example.com'])->call('request');

        Notification::assertNothingSent();
    }
}
