# Project notes for coding agents

- Design source of truth: `docs/SPEC.md`. Keep it updated when behaviour changes.
- `smtp-receiver/` (Go 1.26): run `gofmt -l .`, `go vet ./...`, `go test -race ./...`.
- `web/` (Laravel 13, Filament 5, PHP 8.3): run `vendor/bin/pint` and `php artisan test`. Tests use in-memory SQLite; CI also runs them on MariaDB, so avoid SQLite- or MySQL-only SQL.
- The HMAC scheme is shared between `smtp-receiver/internal/signer` and `web/app/Support/IngestSignature.php`; both have a test with the same vector. Change both together.
- Tenant isolation: tenant panel resources rely on Filament tenancy (`Domain::tenant` / `Tenant::domains`) plus policies in `app/Policies`. Add a cross-tenant test for every new tenant-owned resource.
- Report data is untrusted input: keep the size/ratio limits in `app/Dmarc/ReportExtractor.php` and the DOCTYPE rejection in `AggregateReportParser`.
