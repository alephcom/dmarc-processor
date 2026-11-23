<?php

namespace App\AccountRequests;

use App\Audit\Audit;
use App\Filament\Admin\Resources\AccountRequests\AccountRequestResource;
use App\Models\AccountRequest;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Team\InvitationMail;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Free hosted accounts: the public form files a request, a super-admin approves it (creates the
 * organisation and emails the requester an owner invitation) or declines it.
 */
class AccountRequestService
{
    /** @param array{name: string, email: string, organisation: string, domains: string, message?: ?string} $data */
    public function submit(array $data, ?string $ip, ?string $userAgent): AccountRequest
    {
        $email = strtolower(trim($data['email']));
        // One open request per address: resubmitting doesn't flood the review queue.
        $existing = AccountRequest::pending()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        $req = AccountRequest::create([
            'name' => trim($data['name']),
            'email' => $email,
            'organisation' => trim($data['organisation']),
            'domains' => trim($data['domains']),
            'message' => isset($data['message']) ? trim($data['message']) ?: null : null,
            'ip' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
        ]);
        $this->notifyAdmins($req);

        return $req;
    }

    public function approve(AccountRequest $req, User $by): Tenant
    {
        [$tenant, $invitation, $token] = DB::transaction(function () use ($req, $by) {
            // Lock the row so two admins clicking at once can't create two organisations.
            $this->assertPending(AccountRequest::whereKey($req->id)->lockForUpdate()->firstOrFail());
            $tenant = Tenant::create(['name' => $req->organisation, 'slug' => $this->uniqueSlug($req->organisation)]);
            [$invitation, $token] = Invitation::issue($tenant, $req->email, User::ROLE_OWNER, $by);
            $req->forceFill(['status' => AccountRequest::APPROVED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'tenant_id' => $tenant->id])->save();
            Audit::log('account_request.approved', "Approved account request from {$req->email} ({$req->organisation})", $req, $tenant->id);

            return [$tenant, $invitation, $token];
        });

        Mail::to($req->email)->send(new InvitationMail($invitation, route('invitations.show', $token)));

        return $tenant;
    }

    public function reject(AccountRequest $req, User $by, bool $notify, ?string $note = null): void
    {
        DB::transaction(function () use ($req, $by) {
            $this->assertPending(AccountRequest::whereKey($req->id)->lockForUpdate()->firstOrFail());
            $req->forceFill(['status' => AccountRequest::REJECTED, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
            Audit::log('account_request.rejected', "Declined account request from {$req->email} ({$req->organisation})", $req);
        });
        if ($notify) {
            Mail::to($req->email)->send(new AccountRequestDeclinedMail($req, $note ? trim($note) : null));
        }
    }

    private function assertPending(AccountRequest $req): void
    {
        if (! $req->isPending()) {
            throw new RuntimeException('This request has already been reviewed.');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 56, '') ?: 'org';
        $slug = $base;
        for ($i = 2; Tenant::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function notifyAdmins(AccountRequest $req): void
    {
        $admins = User::where('is_super_admin', true)->get();
        if ($admins->isEmpty()) {
            return;
        }
        $url = AccountRequestResource::getUrl('index', panel: 'admin');
        try {
            Notification::make()
                ->title('New account request')
                ->body("{$req->organisation} ({$req->email})")
                ->info()
                ->actions([Action::make('review')->label('Review')->url($url)])
                ->sendToDatabase($admins);
            Mail::to($admins->pluck('email')->all())->queue(new NewAccountRequestMail($req, $url));
        } catch (Throwable $e) {
            // The request is saved and shows in the admin queue; a mail outage mustn't fail the public form.
            Log::warning('Could not notify admins about account request', ['id' => $req->id, 'error' => $e->getMessage()]);
        }
    }
}
