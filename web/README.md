# web

Laravel + Filament application for the DMARC processor. See the repository root `README.md` and `docs/SPEC.md`.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test
```
