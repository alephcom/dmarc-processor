<?php

return [
    // Domain that receives aggregate reports, e.g. reports.example-msp.com
    'reports_domain' => env('REPORTS_DOMAIN', 'reports.localhost'),

    // Hostname of the MX for the reports domain (also used for the STARTTLS certificate).
    'mx_hostname' => env('MX_HOSTNAME', 'mx.reports.localhost'),

    // Shared secret with smtp-receiver for HMAC-signed internal requests (min 32 chars).
    'ingest_hmac_secret' => env('INGEST_HMAC_SECRET'),

    // Allowed clock skew for signed requests, in seconds.
    'ingest_max_skew' => 300,

    // Filesystem disk and prefix for raw inbound messages.
    'raw_disk' => env('DMARC_RAW_DISK', 'local'),
    'raw_prefix' => 'inbound',

    // Offline IP-to-ASN database (MaxMind DB format). Downloaded by `dmarc:update-ip-db`.
    'asn_db_path' => env('DMARC_ASN_DB', storage_path('app/ipdb/asn.mmdb')),
    // With a (free) IPinfo token we download IPinfo Lite; otherwise DB-IP's free ASN Lite database.
    'ipinfo_token' => env('IPINFO_TOKEN'),

    // How long cached IP intelligence (rDNS, ASN, sender match) stays fresh.
    'ip_intel_ttl_days' => 30,

    // Branding on printable reports and emails (MSP branding; tenants don't customise).
    'brand_name' => env('DMARC_BRAND_NAME', env('APP_NAME', 'DMARC Monitor')),
    'brand_logo_url' => env('DMARC_BRAND_LOGO_URL'), // https URL or /path under public/
    'brand_contact' => env('DMARC_BRAND_CONTACT'),   // e.g. "support@example-msp.com · 0123 456 789"

    // Public home page at "/" explaining the service, with a form to request a free hosted account.
    // Off by default: self-hosters get "/" → "/app" unless they choose to offer hosting too.
    'public_site' => [
        'enabled' => (bool) env('PUBLIC_SITE_ENABLED', false),
        // Monthly email volume we host for free; larger senders are pointed at self-hosting.
        'free_monthly_emails' => (int) env('PUBLIC_SITE_FREE_MONTHLY_EMAILS', 10000),
        'review_hours' => (int) env('PUBLIC_SITE_REVIEW_HOURS', 48),
        'repo_url' => env('PUBLIC_SITE_REPO_URL', 'https://github.com/alephcom/dmarc-processor'),
    ],

    // Default retention for raw reports/records when a tenant doesn't override it.
    'default_retention_days' => 395,
];
