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

    // Default retention for raw reports/records when a tenant doesn't override it.
    'default_retention_days' => 395,
];
