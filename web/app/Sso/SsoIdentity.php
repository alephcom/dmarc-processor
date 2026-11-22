<?php

namespace App\Sso;

/** What we trust from an identity provider after it has validated the sign-in. */
final class SsoIdentity
{
    public function __construct(
        public readonly string $provider,          // google | microsoft
        public readonly string $id,                // stable subject ID from the provider
        public readonly string $email,
        public readonly string $name,
        public readonly bool $emailVerified = false, // Google: email_verified
        public readonly ?string $hostedDomain = null, // Google Workspace: hd
        public readonly ?string $microsoftTenantId = null, // Entra: tid from the validated ID token
    ) {}

    public function emailDomain(): string
    {
        return strtolower(substr(strrchr($this->email, '@') ?: '@', 1));
    }
}
