# Laravel Licensing Client

**Package:** `devwebs01/laravel-license-client`  
**Version:** 1.1.0  
**Architecture:** GitHub Raw JSON Sync — no API server required.

## Installation

```bash
composer require devwebs01/laravel-license-client
```

## Configuration

```env
LICENSING_GITHUB_RAW=https://raw.githubusercontent.com/owner/repo/main
LICENSING_KEY=XXXX-XXXX-XXXX-XXXX
LICENSING_GRACE_DAYS=7
LICENSING_ADMIN_CONTACT=admin@company.com
LICENSING_APP_NAME=MyApp
LICENSING_CACHE_STORE=file
LICENSING_CACHE_TTL=3600
LICENSING_SYNC_INTERVAL=12
LICENSING_DEV_BYPASS=false
LICENSING_HMAC_SECRET=
```

> **Note:** The server pushes `max_devices`, `features`, and `updated_at` fields to GitHub.  
> The client enforces `max_devices` locally (shows `deviceLimitReached` flag) and exposes `features` for feature flag checks.

## Commands

```bash
php artisan license:activate XXXX-XXXX-XXXX-XXXX
php artisan license:status
php artisan license:sync
php artisan license:check
```

## Quick Start

1. Set `LICENSING_GITHUB_RAW` to your GitHub raw content base URL
2. Set `LICENSING_KEY` to the license key
3. Run `php artisan license:activate {key}`
4. Add `->middleware('license')` to protected routes

## How It Works

- License Monitor pushes JSON status files to GitHub (includes `max_devices`, `features`, `expires_at`, `status`)
- Client fetches from `https://raw.githubusercontent.com/.../licenses/{hash}.json`
- Status is cached locally with offline grace period and HMAC integrity check
- Server sync runs on Artisan schedule; stale cache auto-refresh on critical operations
- `license:status` displays all enriched fields; `license_info()` and `license_is_valid()` helpers available

## Testing

```bash
vendor/bin/phpunit
```


