<?php

namespace App\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit entries. Actor, impersonator, IP and user agent come from the current request.
 */
class Audit
{
    public const IMPERSONATOR_SESSION_KEY = 'impersonator_id';

    /** @param array<string, mixed> $changes */
    public static function log(string $action, string $description, ?Model $subject = null, ?int $tenantId = null, array $changes = [], ?int $actorId = null): AuditLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $session = $request?->hasSession() ? $request->session() : null;

        return AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId ?? auth()->id(),
            'impersonator_id' => $session?->get(self::IMPERSONATOR_SESSION_KEY),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'changes' => $changes ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }
}
