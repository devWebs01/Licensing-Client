# PRD: Laravel Licensing Client Package

**Package:** `devwebs01/laravel-licensing-client`  
**Version:** 1.0.0  
**Status:** Draft  
**Filosofi:** *"Simple licensing that is difficult enough to abuse, not impossible to crack."*

---

## Daftar Isi

1. [Ringkasan Eksekutif](#1-ringkasan-eksekutif)
2. [Design Philosophy](#2-design-philosophy)
3. [License Monitor Architecture](#3-license-monitor-architecture)
4. [API Contract](#4-api-contract)
5. [Client Package Overview](#5-client-package-overview)
6. [Core Service: LicenseClientService](#6-core-service-licenseclientservice)
7. [Middleware: CheckLicenseMiddleware](#7-middleware-checklicensemiddleware)
8. [Device Fingerprinting](#8-device-fingerprinting)
9. [Offline Grace Period & Token Strategy](#9-offline-grace-period--token-strategy)
10. [Activation Wizard](#10-activation-wizard)
11. [Feature Flags & Locking](#11-feature-flags--locking)
12. [Artisan Commands](#12-artisan-commands)
13. [Facade & Helper API](#13-facade--helper-api)
14. [Configuration](#14-configuration)
15. [Blade Components](#15-blade-components)
16. [Package Structure](#16-package-structure)
17. [Lifecycle & State Machine](#17-lifecycle--state-machine)
18. [Security Design](#18-security-design)
19. [Device Migration](#19-device-migration)
20. [Error Handling & Recovery](#20-error-handling--recovery)
21. [Testing Strategy](#21-testing-strategy)
22. [Development Roadmap](#22-development-roadmap)
23. [Open Decisions](#23-open-decisions)

---

## 1. Ringkasan Eksekutif

### 1.1 Masalah

Aplikasi Laravel/CodeIgniter yang disewakan ke pelanggan (SaaS on-premise / self-hosted) tidak memiliki mekanisme lisensi untuk:

- Memastikan hanya pelanggan aktif bisa menggunakan aplikasi
- Membatasi jumlah instalasi per lisensi
- Memberikan offline grace period ketika server lisensi tidak reachable
- Mencegah casual abuse dan sharing lisensi massal

### 1.2 Solusi

Package Composer (`devwebs01/laravel-licensing-client`) yang:

- Mengintegrasikan aplikasi client dengan License Monitor server via REST API
- Menyediakan middleware protection untuk semua route
- Activation wizard untuk bootstrap lisensi
- Device fingerprinting untuk binding
- Offline grace period via encrypted cached token
- Feature flags dari server
- Readonly/lock mode ketika lisensi expired

### 1.3 Target Pengguna

**Developer** yang mengintegrasikan package ke aplikasi client:
- Butuh setup cepat (composer require + config)
- Middleware siap pakai
- Zero database migration di client
- Dokumentasi jelas

**End-user** (pelanggan yang menyewa aplikasi):
- Activation wizard yang jelas
- Grace period warning sebelum lock
- Tidak perlu teknis untuk aktivasi

### 1.4 Bukan Scope

- ✅ Yang Dilakukan: validasi subscription, offline grace, device binding, feature flags, periodic revalidation
- ❌ Bukan Dilakukan: military-grade anti-cracking, kernel-level protection, obfuscation ekstrem, DRM, blockchain validation, distributed revocation

---

## 2. Design Philosophy

### 2.1 Prinsip Utama

1. **Simple over perfect.** Pilih solusi yang 80% efektif dengan 20% effort.
2. **Casual abuse prevention.** Target: pengguna "biasa" tidak bisa bypass dengan mudah. Jika ada dedicated attacker dengan akses penuh ke source code, mereka selalu bisa bypass.
3. **Offline-first.** Aplikasi harus tetap berfungsi ketika server lisensi tidak reachable. Jangan ganggu operasional bisnis pelanggan karena server monitoring mati.
4. **Operational sustainability.** Package harus bisa di-maintain oleh small team tanpa dedicated DevOps.
5. **Laravel developer experience.** Ikuti Laravel conventions: Facades, config publish, artisan commands, middleware auto-registration.

### 2.2 Trade-off yang Diterima

| Trade-off | Keputusan | Alasan |
|-----------|-----------|--------|
| Token bisa di-forge jika APP_KEY bocor | Accept | Jika attacker sudah punya akses ke APP_KEY, game sudah berakhir |
| Fingerprint berubah setelah server migration | Accept | Grace period 24h untuk re-activation |
| Tidak ada revocation network | Accept | Revocation dilakukan via periodic validation (max 7 hari delay) |
| No kernel-level obfuscation | Accept | Overengineering untuk UMKM/SaaS kecil |

### 2.3 Security Target Realistis

| Ancaman | Level Proteksi | Mekanisme |
|---------|---------------|-----------|
| Copy-paste folder ke server lain | Medium | Device fingerprint binding |
| Manual set cookie/session "licensed" | High | Encrypted server-signed token |
| Clock tampering (mundurkan tanggal) | Medium | Server timestamp + signed payload |
| Sharing license key massal | Medium | Device binding + max_devices limit |
| Disable network biar ga kena revoke | Medium | Grace period max 7 hari |
| Decompile & extract token key | Low-| PHP source accessible, APP_KEY protection |
| Modify middleware to skip check | Low-| Attacker dengan akses source code |

---

## 3. License Monitor Architecture

### 3.1 Server yang Sudan Dibangun

License Monitor server sudah berjalan dengan:

**Database tables:**
- `products` — produk/aplikasi yang dilisensikan
- `licenses` — license keys dengan status (active/suspended/expired/revoked)
- `devices` — device terdaftar per license
- `activation_requests` — request approval untuk aktivasi manual
- `subscriptions` — subscription data
- `audit_logs` — audit trail

**API Endpoints:**

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| POST | `/api/v1/activate` | Aktivasi device baru |
| GET | `/api/v1/verify/{key}/{fingerprint}` | Verifikasi activation code |
| GET | `/api/v1/status/{key}/{fingerprint}` | Status lisensi + device |
| POST | `/api/v1/validate` | Validasi online penuh |
| POST | `/api/v1/check-update` | Cek update versi |

### 3.2 Endpoint Baru yang Diperlukan

Untuk mendukung deaktivasi dari client, perlu endpoint baru:

| Method | Endpoint | Deskripsi | Priority |
|--------|----------|-----------|----------|
| POST | `/api/v1/deactivate` | Deaktivasi device | Medium |

### 3.3 Server Response Standard

Semua endpoint mengembalikan format:

```json
{
  "success": true,
  "message": "Pesan",
  "data": { ... }
}
```

Error response:

```json
{
  "success": false,
  "message": "Error message",
  "errors": null
}
```

---

## 4. API Contract

### 4.1 POST `/api/v1/activate` — Aktivasi Device

**Request:**
```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "device": {
    "fingerprint": "a3f2b8c1...64chars...",
    "name": "Server Production",
    "platform": "linux",
    "platform_version": "Ubuntu 22.04",
    "app_version": "1.0.0"
  }
}
```

**Success Response (200) — auto-activated (offline mode):**
```json
{
  "success": true,
  "message": "Perangkat berhasil diaktifkan",
  "data": {
    "device_id": 1,
    "offline_until": "2026-05-23T13:00:00Z"
  }
}
```

**Success Response (200) — requires approval (online/semi-online mode):**
```json
{
  "success": true,
  "message": "Kode aktivasi dibuat",
  "data": {
    "requires_approval": true,
    "activation_code": "A7F3B2C1",
    "expires_at": "2026-05-16T13:30:00Z"
  }
}
```

**Error Response (404):**
```json
{
  "success": false,
  "message": "Kunci lisensi tidak valid"
}
```

### 4.2 GET `/api/v1/verify/{key}/{fingerprint}?code=...` — Verifikasi Approval

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "offline_until": "2026-05-23T13:00:00Z"
  }
}
```

### 4.3 GET `/api/v1/status/{key}/{fingerprint}` — Status Check

**Response (200):**
```json
{
  "success": true,
  "data": {
    "license_valid": true,
    "license_status": "active",
    "device_activated": true,
    "offline_until": "2026-05-23T13:00:00Z"
  }
}
```

### 4.4 POST `/api/v1/validate` — Online Validation

**Request:**
```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "device": {
    "fingerprint": "a3f2b8c1...64chars..."
  }
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Lisensi valid",
  "data": {
    "valid": true,
    "status": "active",
    "license_key": "XXXX-****-****-XXXX",
    "product": "Nama Produk",
    "expires_at": "2026-06-16",
    "max_devices": 3,
    "devices_count": 1,
    "cache_until": "2026-05-23"
  }
}
```

**Endpoint ini adalah PRIMARY SOURCE OF TRUTH untuk client package.**  
Client akan call endpoint ini untuk refresh status dan memperpanjang cache.

### 4.5 POST `/api/v1/deactivate` — Deaktivasi Device

**Request:**
```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "device": {
    "fingerprint": "a3f2b8c1...64chars..."
  }
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Perangkat berhasil dideaktivasi"
}
```

---

## 5. Client Package Overview

### 5.1 Arsitektur High-Level

```
[Aplikasi Client (Laravel)]
    │
    ├── [CheckLicenseMiddleware]
    │     ↓ redirect jika belum aktivasi
    │     ↓ block jika expired/locked
    │
    ├── [LicenseClientService]
    │     ↓ HTTP call ke License Monitor
    │     ↓ cache encrypted token
    │
    ├── [LicenseCacheService]
    │     ↓ read/write encrypted cache
    │
    ├── [FingerprintCollector]
    │     ↓ generate device fingerprint
    │
    └── [Activation Wizard]
          ↓ Blade views untuk bootstrap
          ↓ Grace countdown widget
          ↓ Lock screen
```

### 5.2 Dependencies

| Dependency | Version | Catatan |
|-----------|---------|---------|
| PHP | ^8.1 | - |
| `laravel/framework` | ^10.0 \| ^11.0 \| ^12.0 \| ^13.0 | Support multiple major versions |
| `guzzlehttp/guzzle` | ^7.0 | HTTP client (Laravel built-in) |

**Tidak ada dependency lain.** Package harus ringan.

### 5.3 Zero Database Migration

Package **tidak boleh** membuat tabel di database client. Semua state disimpan di:

1. **Laravel Cache** (`file` driver default) — encrypted token
2. **Config** — license key, server URL
3. **Session** — temporary flash messages untuk wizard

---

## 6. Core Service: LicenseClientService

### 6.1 Method Signature

```php
class LicenseClientService
{
    /**
     * Aktivasi license key dengan fingerprint device.
     * Call POST /api/v1/activate
     */
    public function activate(string $licenseKey): ActivationResult;

    /**
     * Verifikasi activation code (untuk mode approval).
     * Call GET /api/v1/verify/{key}/{fingerprint}
     */
    public function verifyActivation(string $code): bool;

    /**
     * Validasi lisensi online. Refresh cache jika valid.
     * Call POST /api/v1/validate
     */
    public function validateOnline(): ValidationResult;

    /**
     * Validasi dari cache offline.
     * Decrypt dan cek expiry.
     */
    public function validateOffline(): ValidationResult;

    /**
     * Status lisensi lengkap (cache + server).
     */
    public function status(): LicenseInfo;

    /**
     * Deaktivasi device.
     * Call POST /api/v1/deactivate
     */
    public function deactivate(): bool;
}
```

### 6.2 Value Objects

```php
readonly class ActivationResult
{
    public bool $success;
    public bool $requiresApproval;
    public ?string $activationCode;
    public ?string $offlineUntil;
    public ?string $message;
}

readonly class ValidationResult
{
    public bool $valid;
    public LicenseStatus $status;        // active, suspended, expired, revoked
    public ?string $offlineUntil;
    public ?string $product;
    public ?string $expiresAt;
    public int $maxDevices;
    public int $devicesCount;
    public ?string $message;
}

readonly class LicenseInfo
{
    public bool $isValid;
    public LicenseStatus $status;
    public ?string $offlineUntil;
    public bool $isWithinGracePeriod;
    public int $graceDaysRemaining;
    public ?string $product;
    public ?string $cachedAt;
    public bool $requiresOnlineRefresh;
}
```

### 6.3 Caching Strategy

```php
// Cache key namespace
const CACHE_KEY_TOKEN = 'licensing:token';     // Encrypted payload
const CACHE_KEY_META  = 'licensing:meta';      // Metadata (last_check, etc)

// Cache TTL
const CACHE_TTL = 3600;  // 1 jam — revalidation frequency

const GRACE_DAYS = 7;    // Grace period setelah last successful validation

// Encrypted token content (disimpan di cache)
[
    'license_key'    => 'XXXX-XXXX-XXXX-XXXX',
    'fingerprint'    => 'a3f2b8c1...',
    'status'         => 'active',
    'product'        => 'Nama Produk',
    'expires_at'     => '2026-06-16',
    'offline_until'  => '2026-05-23T13:00:00Z',
    'cached_at'      => '2026-05-16T13:00:00Z',
    'server_time'    => '2026-05-16T13:00:00Z',  // Anti clock tampering
    'features'       => ['pos', 'reports', 'users'],
    'hmac'           => 'sha256-of-payload-with-secret',
]

// Token dienkripsi dengan Crypt::encryptString() sebelum disimpan
```

### 6.4 Clock Tampering Protection

```
server_time dicatat saat validasi online.
Saat offline validation:
  - Hitung elapsed_time = cached_at - server_time
  - Bandingkan dengan current_time - cached_at
  - Jika selisih > 1 jam → curigai clock tampering → force online refresh
```

---

## 7. Middleware: CheckLicenseMiddleware

### 7.1 Registration

```php
// ServiceProvider
public function boot(): void
{
    $this->app['router']->aliasMiddleware('license', CheckLicenseMiddleware::class);
}
```

### 7.2 Lifecycle (3-Step)

```
Request → CheckLicenseMiddleware::handle()
    │
    ├── Step 1: CEK CACHE
    │     ├── Cache ada dan valid?
    │     │   ├── ✅ offline_until masih future?
    │     │   │   ├── ✅ LANJUTKAN (proceed)
    │     │   │   └── ❌ grace habis → Step 3
    │     │   └── ❌ cache tidak ada → Step 2
    │     │
    ├── Step 2: VALIDASI ONLINE
    │     ├── POST /api/v1/validate
    │     │   ├── ✅ Sukses → simpan cache → LANJUTKAN
    │     │   ├── ❌ License invalid (expired/suspended)
    │     │   │   └── Hapus cache → 🚫 LOCK (redirect ke lock page)
    │     │   └── ❌ Network error (server unreachable)
    │     │       └── Step 3
    │     │
    └── Step 3: GRACE PERIOD CHECK
          ├── Cache masih ada dan dalam grace period?
          │   ├── ✅ LANJUTKAN + flash warning
          │   └── ❌ 🚫 REDIRECT ke activation wizard
          │
          └── No cache at all?
                └── 🚫 REDIRECT ke activation wizard
```

### 7.3 Excluded Routes

Routes berikut dikecualikan dari middleware:

- `licensing/*` — activation wizard routes
- `login`, `register`, `password/*` — auth routes (jika middleware ditempatkan setelah auth)

### 7.4 Grace Warning Flash

Saat dalam grace period, middleware menambahkan flash message:

```php
if ($withinGracePeriod && $daysRemaining <= 3) {
    session()->flash('license_warning', "Lisensi akan expired dalam {$daysRemaining} hari. Hubungi admin.");
}
```

---

## 8. Device Fingerprinting

### 8.1 FingerprintCollector

```php
class FingerprintCollector
{
    /**
     * Koleksi data environment untuk membuat fingerprint unik.
     * Hash SHA256 dari komponen-komponen:
     * - hostname (php_uname('n'))
     * - OS + kernel (php_uname('s') . php_uname('r'))
     * - Document root / app path
     * - Database path atau connection name
     * - PHP version
     */
    public function collect(): string;

    /**
     * Format: 64 karakter hex (SHA256)
     * Contoh: a3f2b8c1d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0
     */
    public function fingerprint(): string;
}
```

### 8.2 Komponen Fingerprint

| Komponen | Sumber | Stabilitas |
|----------|--------|------------|
| hostname | `php_uname('n')` | Stabil (kecuali ganti server) |
| OS + kernel | `php_uname('s') . php_uname('r')` | Stabil |
| Document root | `$_SERVER['DOCUMENT_ROOT']` ?? `__DIR__` | Stabil |
| App path | `base_path()` | Stabil |
| Database name | `config('database.connections.' . config('database.default') . '.database')` | Stabil |

### 8.3 Shared Hosting Consideration

Di shared hosting, hostname dan OS bisa sama dengan tenant lain. Untuk meningkatkan uniqueness, tambahkan:

- `realpath(base_path())` — absolute path ke aplikasi
- Jika ada, kombinasi dengan `APP_KEY` (tanpa menyimpan raw key)

### 8.4 Fingerprint Change Handling

Fingerprint berubah ketika:
- Server migrasi ke hardware baru
- Hostname berubah
- Path aplikasi berubah

**Strategy:** Jika server mengembalikan 404 (device not found untuk fingerprint baru), client akan:
1. Memberitahu user bahwa "Perangkat telah berubah, hubungi admin untuk aktivasi ulang"
2. Atau jika license memiliki slot tersisa, auto-register fingerprint baru

---

## 9. Offline Grace Period & Token Strategy

### 9.1 Grace Period Calculation

```
offline_until = MAX(
    cached_offline_until,           // dari aktivasi terakhir
    last_successful_validate + 7 hari  // dari validasi online
)
```

Setiap kali `POST /api/v1/validate` sukses, `offline_until` diperpanjang.

### 9.2 Token Storage

```php
// ENKRIPSI
$token = [
    'license_key'   => $licenseKey,
    'fingerprint'   => $fingerprint,
    'status'        => 'active',
    'product'       => 'Nama Produk',
    'expires_at'    => '2026-06-16',
    'offline_until' => '2026-05-23T13:00:00Z',
    'cached_at'     => now()->toIso8601String(),
    'server_time'   => $serverTime,   // dari response server
    'features'      => ['pos', 'reports'],
    'version'       => 1,             // token version for future migration
];

$encrypted = Crypt::encryptString(json_encode($token));
Cache::put('licensing:token', $encrypted, now()->addDays(30));

// DECRIPSI
$decrypted = json_decode(Crypt::decryptString($cached), true);
```

### 9.3 Token Format Versioning

```
token_version = 1 (current)
Jika ada perubahan struktur token di masa depan:
- Increment version
- Cek version saat decrypt
- Jika old version, validasi sebisa mungkin + force online refresh
```

### 9.4 Anti Clock Tampering

```php
// Saat offline validation:
$elapsedSinceCached = now()->diffInSeconds($cachedAt);
$elapsedSinceServer = $serverTime->diffInSeconds($cachedAt);
$clockDrift = abs($elapsedSinceCached - $elapsedSinceServer);

if ($clockDrift > 3600) {  // lebih dari 1 jam drift
    // Curigai clock tampering, force online refresh
    throw new ClockDriftDetectedException;
}
```

---

## 10. Activation Wizard

### 10.1 Flow

```
User buka aplikasi
    ↓
Middleware deteksi "belum ada lisensi"
    ↓
Redirect ke /licensing/activate
    ↓
Halaman 1: SELAMAT DATANG
    ├── Informasi: "Aplikasi ini memerlukan lisensi"
    ├── Tombol: "Mulai Aktivasi"
    │
    ↓
Halaman 2: DETEKSI PERANGKAT
    ├── Menampilkan fingerprint (untuk verifikasi)
    ├── Informasi OS, hostname
    ├── Tombol: "Lanjutkan"
    │
    ↓
Halaman 3: MASUKKAN LISENSI
    ├── Input: License Key (XXXX-XXXX-XXXX-XXXX)
    ├── Validasi format client-side
    ├── Tombol: "Aktivasi"
    │
    ↓
Halaman 4: PROSES AKTIVASI
    ├── Loading state
    ├── Call POST /api/v1/activate
    │
    ├── ✅ SUKSES (auto-activation)
    │   ├── Simpan token
    │   ├── Redirect ke dashboard
    │   └── Flash: "Aktivasi berhasil!"
    │
    ├── ⏳ MENUNGGU APPROVAL
    │   ├── Tampilkan activation code
    │   ├── Informasi: "Hubungi admin dengan kode ini"
    │   ├── Auto-poll tiap 30 detik ke /api/v1/verify
    │   └── Jika approved → simpan token → redirect dashboard
    │
    └── ❌ GAGAL
        ├── Tampilkan error message
        ├── Tombol: "Coba Lagi"
        └── Tombol: "Hubungi Admin"
```

### 10.2 Routes

```
GET  /licensing/activate        → wizard step 1
POST /licensing/activate        → submit license key
GET  /licensing/status          → current license status
GET  /licensing/locked          → lock screen (pada saat expired)
```

### 10.3 Blade Views

```
resources/views/
└── vendor/licensing-client/
    ├── wizard-step-1.blade.php       # Welcome screen
    ├── wizard-step-2.blade.php       # Device detection
    ├── wizard-step-3.blade.php       # License key input
    ├── wizard-step-4.blade.php       # Activation processing
    ├── approved.blade.php            # Waiting for approval
    ├── locked.blade.php              # License expired / locked
    └── components/
        ├── countdown-warning.blade.php    # Grace period countdown
        └── status-badge.blade.php         # License status indicator
```

Views harus bisa di-customize oleh aplikasi client via `vendor:publish`.

---

## 11. Feature Flags & Locking

### 11.1 Feature Flags

Server mengembalikan array feature flags di response:

```json
{
  "data": {
    "features": ["pos", "reports", "users"]
  }
}
```

Client package menyediakan helper:

```php
// Di Blade
@feature('reports')
    <flux:button>Lihat Laporan</flux:button>
@endfeature

// Di PHP
$client->hasFeature('reports');  // true/false
```

### 11.2 App States

| State | UI | Behavior |
|-------|----|----------|
| **Active** | Normal | Semua fitur berjalan |
| **Grace Warning** | Countdown banner (jika <= 3 hari) | Semua fitur berjalan + peringatan |
| **Grace Expired** | Lock screen | Hanya halaman licensing yang bisa diakses |
| **Suspended** | Lock screen + alasan | Blok total |
| **Revoked** | Lock screen + kontak admin | Blok total |
| **Not Activated** | Redirect ke wizard | Tidak ada akses |

### 11.3 Lock Screen

Halaman `/licensing/locked` menampilkan:

- Status lisensi (expired / suspended / revoked)
- Kontak admin (configurable)
- Grace period sudah habis
- Tombol "Coba Validasi Ulang" (force online check)
- Tombol "Aktivasi Ulang" (reset wizard)

---

## 12. Artisan Commands

### 12.1 Command: `license:activate {key}`

```bash
php artisan license:activate XXXX-XXXX-XXXX-XXXX
```

**Flow:**
1. Generate fingerprint
2. Call `POST /api/v1/activate`
3. Jika sukses, simpan token
4. Output ke console

**Use case:** Headless server, provisioning script, atau debug.

### 12.2 Command: `license:status`

```bash
php artisan license:status
```

**Output:**
```
License Status
──────────────
Status:         Active
Product:        Laravel POS
License Key:    XXXX-****-XXXX-XXXX
Expires At:     2026-06-16
Offline Until:  2026-05-23
Cache Age:      2 hours
Device:         a3f2b8c1...64chars... (Server Production)
Features:       pos, reports, users
```

---

## 13. Facade & Helper API

### 13.1 Facade: `LicenseClient`

```php
use DevWebs01\LicensingClient\Facades\LicenseClient;

// Cek status lisensi (cache + fallback ke server)
LicenseClient::isValid(): bool;

// Info lisensi lengkap
LicenseClient::info(): LicenseInfo;

// Aktivasi
LicenseClient::activate(string $key): ActivationResult;

// Verifikasi activation code
LicenseClient::verifyActivation(string $code): bool;

// Force online refresh
LicenseClient::refresh(): bool;

// Deaktivasi
LicenseClient::deactivate(): bool;

// Cek feature flag
LicenseClient::hasFeature(string $feature): bool;
```

**Total: 7 methods** — balanced antara completeness dan simplicity.

### 13.2 Blade Directives

```blade
@licensed
    Konten hanya untuk lisensi aktif
@endlicensed

@feature('reports')
    Konten untuk fitur reports
@endfeature

@licenseWarning
    Menampilkan grace period warning (jika dalam masa grace)
@endlicenseWarning
```

### 13.3 Blade Components

```blade
{{-- Grace period countdown --}}
<flux:card>
    <x-licensing::countdown-warning />
</flux:card>

{{-- Status badge di sidebar --}}
<x-licensing::status-badge />

{{-- Lock screen --}}
<x-licensing::locked-screen />
```

---

## 14. Configuration

### 14.1 Config File: `config/licensing-client.php`

```php
<?php

return [
    /*
     * URL License Monitor server.
     * Contoh: https://monitor.devwebs01.com
     */
    'server_url' => env('LICENSING_SERVER_URL'),

    /*
     * License key untuk aplikasi ini.
     * Format: XXXX-XXXX-XXXX-XXXX
     */
    'license_key' => env('LICENSING_KEY'),

    /*
     * Nama aplikasi yang terdaftar di server.
     * Digunakan untuk display dan validasi.
     */
    'app_name' => env('LICENSING_APP_NAME', env('APP_NAME')),

    /*
     * Environment aplikasi.
     * licenses: development/license akan bypass middleware di local.
     * production: full validation.
     */
    'environment' => env('LICENSING_ENV', env('APP_ENV')),

    /*
     * Cache configuration.
     */
    'cache' => [
        'store' => env('LICENSING_CACHE_STORE', env('CACHE_STORE', 'file')),
        'ttl_seconds' => env('LICENSING_CACHE_TTL', 3600),
    ],

    /*
     * Grace period dalam hari.
     * Default: 7 hari sejak validasi online terakhir.
     */
    'grace_days' => env('LICENSING_GRACE_DAYS', 7),

    /*
     * HTTP client timeout dalam detik.
     */
    'timeout' => env('LICENSING_TIMEOUT', 10),

    /*
     * Route prefix untuk licensing pages.
     */
    'route_prefix' => 'licensing',

    /*
     * Routes yang dikecualikan dari middleware.
     */
    'excluded_routes' => [
        'login',
        'register',
        'password/*',
        'licensing/*',
    ],

    /*
     * Kontak admin untuk ditampilkan di lock screen.
     */
    'admin_contact' => env('LICENSING_ADMIN_CONTACT', 'admin@company.com'),

    /*
     * Development mode bypass.
     * Jika true, middleware tidak akan memblokir di environment local.
     */
    'dev_bypass' => env('LICENSING_DEV_BYPASS', false),
];
```

### 14.2 Environment Variables

```env
LICENSING_SERVER_URL=https://monitor.devwebs01.com
LICENSING_KEY=
LICENSING_APP_NAME=
LICENSING_ENV=production
LICENSING_CACHE_TTL=3600
LICENSING_GRACE_DAYS=7
LICENSING_TIMEOUT=10
LICENSING_ADMIN_CONTACT=admin@company.com
LICENSING_DEV_BYPASS=false
```

---

## 15. Package Structure

```
devwebs01/laravel-licensing-client/
│
├── src/
│   ├── Commands/
│   │   ├── LicenseActivateCommand.php     # license:activate {key}
│   │   └── LicenseStatusCommand.php        # license:status
│   │
│   ├── Components/
│   │   ├── CountdownWarning.php            # Blade component
│   │   ├── StatusBadge.php                 # Blade component
│   │   └── LockedScreen.php                # Blade component
│   │
│   ├── Exceptions/
│   │   ├── LicenseNotActivatedException.php
│   │   ├── LicenseExpiredException.php
│   │   ├── LicenseSuspendedException.php
│   │   ├── ServerUnreachableException.php
│   │   └── ClockDriftDetectedException.php
│   │
│   ├── Facades/
│   │   └── LicenseClient.php               # Facade
│   │
│   ├── Http/
│   │   └── Middleware/
│   │       └── CheckLicenseMiddleware.php   # license middleware
│   │
│   ├── Services/
│   │   ├── LicenseClientService.php         # Main service (HTTP + cache)
│   │   ├── LicenseCacheService.php          # Cache management + encryption
│   │   └── FingerprintCollector.php         # Device fingerprint
│   │
│   ├── ValueObjects/
│   │   ├── ActivationResult.php
│   │   ├── ValidationResult.php
│   │   └── LicenseInfo.php
│   │
│   ├── BladeDirectives.php                 # @licensed, @feature, @licenseWarning
│   ├── LicensingClientServiceProvider.php   # Service provider
│   └── helpers.php                          # Optional helper functions
│
├── resources/
│   └── views/
│       ├── activate.blade.php               # Wizard utama (combined steps)
│       ├── locked.blade.php                  # Lock screen
│       └── components/
│           ├── countdown-warning.blade.php
│           ├── status-badge.blade.php
│           └── loading-spinner.blade.php
│
├── routes/
│   └── licensing.php                        # Route: licensing/*
│
├── config/
│   └── licensing-client.php                 # Default config (publishable)
│
├── tests/
│   ├── TestCase.php                         # Base test case
│   ├── Unit/
│   │   ├── LicenseClientServiceTest.php
│   │   ├── LicenseCacheServiceTest.php
│   │   ├── FingerprintCollectorTest.php
│   │   └── ValueObjectsTest.php
│   └── Feature/
│       ├── CheckLicenseMiddlewareTest.php
│       ├── ActivationWizardTest.php
│       └── LicenseActivateCommandTest.php
│
├── composer.json
├── phpunit.xml
├── pint.json
└── README.md
```

**Total: ~35 files.** Manageable, fokus, tidak overengineered.

---

## 16. Lifecycle & State Machine

### 16.1 App States

```
                    ┌──────────────┐
                    │ NOT ACTIVATED │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │  ACTIVATING  │
                    └──────┬───────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
       ┌──────────┐ ┌──────────┐ ┌──────────┐
       │  ACTIVE  │ │ PENDING  │ │  FAILED  │
       └────┬─────┘ │ APPROVAL │ └────┬─────┘
            │       └──────────┘      │
            │              │          │
            │       ┌──────┘          │
            │       ▼                 │
            │  ┌──────────┐           │
            │  │ APPROVED │           │
            │  └────┬─────┘           │
            │       │                │
            ▼       ▼                │
       ┌─────────────────────┐       │
       │       ACTIVE        │◄──────┘
       └──┬──────┬──────┬────┘
          │      │      │
          ▼      ▼      ▼
   ┌────────┐ ┌──────┐ ┌──────┐
   │ GRACE  │ │SUSP. │ │EXPIRE│
   │WARNING │ │ENDED │ │D     │
   └───┬────┘ └──────┘ └──────┘
       │
       ▼
  ┌────────┐
  │ LOCKED │
  └────────┘
```

### 16.2 State Transitions

| From | To | Trigger |
|------|----|---------|
| NOT_ACTIVATED | ACTIVATING | User submits license key |
| ACTIVATING | ACTIVE | Server returns auto-activated |
| ACTIVATING | PENDING_APPROVAL | Server returns requires_approval=true |
| PENDING_APPROVAL | ACTIVE | Server approves (via verify endpoint) |
| ACTIVE | GRACE_WARNING | Cache expired, server unreachable, within grace |
| GRACE_WARNING | ACTIVE | Successful online validation (reset grace) |
| GRACE_WARNING | LOCKED | Grace period expired |
| ACTIVE | LOCKED | Server returns suspended/expired/revoked |
| LOCKED | ACTIVATING | User re-activates |

---

## 17. Security Design

### 17.1 Threat Model

| Threat | Mitigation | Residual Risk |
|--------|------------|---------------|
| Token file copy ke server lain | Encrypted with APP_KEY, bound to fingerprint | Low — different finger-print, server reject |
| Session manipulation | Server-side session, not client | Very Low |
| Middleware bypass | Hard to bypass without modifying vendor | Medium — source access = bypass |
| Token replay | Offline_until expiry + periodic validation | Low |
| Race condition during validation | Locking via Cache::lock() | Very Low |
| Cache poisoning | Encrypted token, HMAC verification | Very Low |

### 17.2 Encryption

```php
// ENKRIPSI TOKEN
$encrypted = Crypt::encryptString(json_encode($token));

// VERIFIKASI INTEGRITAS
$payload = $token['license_key'] . $token['fingerprint'] . $token['offline_until'];
$expectedHmac = hash_hmac('sha256', $payload, $this->getHmacSecret());
if (! hash_equals($expectedHmac, $token['hmac'])) {
    throw new CorruptedTokenException;
}
```

### 17.3 What We Accept

1. **PHP source accessible** — ya, ini web app. Tidak ada proteksi source code.
2. **APP_KEY bisa di-extract** — ya, jika attacker punya akses filesystem.
3. **Middleware bisa dihapus** — ya, jika attacker punya akses untuk edit kode.
4. **Vendor folder bisa dimodifikasi** — ya, PHP tidak punya code signing.

**Target proteksi:** Casual user yang mencoba copy-paste folder ke server lain. Bukan dedicated reverse engineer.

---

## 18. Device Migration

### 18.1 Skenario

1. **Server upgrade** — hostname berubah, fingerprint berubah
2. **Domain change** — URL aplikasi berubah
3. **Database migration** — path database berubah

### 18.2 Approach

Ketika fingerprint berubah tetapi license key sama:

1. Client call `POST /api/v1/activate` dengan fingerprint baru
2. Server cek: IP/request dari klien yang terdaftar? (optional geolocation)
3. Jika license punya slot tersisa → auto-accept sebagai device baru
4. Jika tidak punya slot → return error "Batas perangkat tercapai"
5. Opsional: admin bisa approve device baru via panel

**Alternative sederhana:** Berikan grace period 24 jam setelah fingerprint change sebelum lock.

---

## 19. Error Handling & Recovery

### 19.1 Error Matrix

| Error | Client Action | User Message |
|-------|--------------|--------------|
| Server 404 (invalid key) | Tampilkan error | "Kunci lisensi tidak valid" |
| Server 403 (suspended) | Hapus cache, lock | "Lisensi ditangguhkan, hubungi admin" |
| Server 403 (expired) | Hapus cache, lock | "Lisensi telah kedaluwarsa" |
| Server 409 (pending exists) | Tampilkan existing code | "Kode aktivasi sebelumnya: XXXX" |
| Network timeout | Fallback ke grace period | "Server lisensi tidak reachable" |
| Corrupted cache | Hapus cache, force online | - (silent recovery) |
| Clock drift detected | Force online refresh | "Waktu server tidak sinkron" |

### 19.2 Recovery Flow

```
Error terjadi
    ↓
Apakah ada cache valid?
    ├── Ya → pakai grace period → lanjut
    └── Tidak → redirect ke activation wizard
         ↓
User bisa:
    ├── Call admin untuk aktivasi manual
    ├── Cek koneksi internet
    └── Input ulang license key
```

---

## 20. Testing Strategy

### 20.1 Test Categories

| Category | Framework | Target Coverage |
|----------|-----------|-----------------|
| Unit tests | PHPUnit | 90%+ service classes |
| Middleware tests | PHPUnit with HTTP mock | All state transitions |
| Feature tests | PHPUnit with Http::fake() | Full activation flow |

### 20.2 Key Test Scenarios

**Unit:**
- FingerprintCollector returns consistent hash for same input
- LicenseCacheService encrypts/decrypts correctly
- Token integrity verification (valid + corrupted)
- Clock drift detection

**Middleware:**
- Valid cache → request proceeds
- No cache → redirect to wizard
- Expired cache + server unreachable + grace → proceed with warning
- Expired cache + server unreachable + no grace → redirect to lock
- Expired cache + server returns "suspended" → lock
- Excluded routes bypass middleware

**Feature:**
- Full activation flow: key input → server call → token cache → dashboard
- Approval flow: activate → pending → verify → active
- Lock screen: expired license → lock → re-activate

### 20.3 Mock Strategy

```php
// Mock HTTP calls
Http::fake([
    'monitor.test/api/v1/validate' => Http::response([
        'success' => true,
        'data' => [
            'valid' => true,
            'status' => 'active',
            'offline_until' => now()->addDays(7)->toIso8601String(),
            'features' => ['pos', 'reports'],
        ],
    ]),
]);

// Mock cache
Cache::shouldReceive('get')
    ->with('licensing:token')
    ->andReturn($encryptedToken);
```

---

## 21. Development Roadmap

### 21.1 Phase 1: Core (Week 1)

| Task | Est. Hours | Dependencies |
|------|-----------|--------------|
| Package scaffolding (composer.json, ServiceProvider) | 2 | - |
| FingerprintCollector + tests | 3 | - |
| Value Objects (ActivationResult, etc.) | 2 | - |
| LicenseCacheService (encrypt/decrypt cache) | 4 | - |
| LicenseClientService — activate + validate + status | 6 | API contract finalized |

**Deliverable:** Core services work via artisan tinker.

### 21.2 Phase 2: Integration (Week 2)

| Task | Est. Hours | Dependencies |
|------|-----------|--------------|
| CheckLicenseMiddleware + tests | 6 | Core services |
| Blade directives + components | 3 | - |
| Artisan commands | 3 | Core services |
| Config + env setup | 2 | - |

**Deliverable:** Full middleware protection, artisan commands work.

### 21.3 Phase 3: UX (Week 3)

| Task | Est. Hours | Dependencies |
|------|-----------|--------------|
| Activation wizard views | 6 | Middleware |
| Lock screen | 3 | - |
| Grace countdown widget | 3 | - |
| Error handling + recovery UX | 4 | Phase 1 |

**Deliverable:** Full UX flow.

### 21.4 Phase 4: Polish (Week 4)

| Task | Est. Hours | Dependencies |
|------|-----------|--------------|
| Feature flags integration | 3 | Core services |
| Device migration handling | 3 | FingerprintCollector |
| Comprehensive tests | 6 | All phases |
| Documentation + README | 4 | - |
| CI/CD + Packagist publish | 2 | - |

**Deliverable:** Production-ready package.

### 21.5 Total Estimated Effort

| Phase | Hours |
|-------|-------|
| Phase 1: Core | 17 |
| Phase 2: Integration | 14 |
| Phase 3: UX | 16 |
| Phase 4: Polish | 18 |
| **Total** | **65 hours** |

---

## 22. Open Decisions

| Decision | Options | Recommended | Reason |
|----------|---------|-------------|--------|
| License key format | With prefix `LIC-` or without | Without `LIC-` | Compatible with existing server |
| Server endpoint untuk deaktivasi | Buat baru `/deactivate` atau pakai ping | Buat baru | Clean REST semantics |
| Feature flags dari server | Array string atau object | Array string | Simple, cukup untuk boolean flags |
| Grace period configurable per license? | Server-side di product atau client config | Client config via `grace_days` | Simpler, no server change needed |
| Multi-tenant support di client | Via config atau auto-detect | Config `app_name` per tenant | Explicit lebih baik dari magic |
| CodeIgniter support | Separate package atau adapter | Separate package v2 | Berbeda fundamental dengan Laravel |
| Cache store fallback | Coba cache, fallback ke file | No fallback — file default stabil | Simpler, predictable |

---

## 23. Glossary

| Term | Definisi |
|------|----------|
| **License Key** | Kode unik format `XXXX-XXXX-XXXX-XXXX` yang mengidentifikasi lisensi |
| **Device Fingerprint** | SHA256 hash dari environment variables untuk identifikasi device |
| **Offline Token** | Encrypted JSON payload yang disimpan di cache untuk offline validation |
| **Grace Period** | Masa tenggang setelah cache expired di mana aplikasi tetap berjalan |
| **Activation Wizard** | Flow multi-step untuk aktivasi lisensi pertama kali |
| **Feature Flags** | Array fitur yang diizinkan oleh lisensi, dikembalikan oleh server |
| **Lock Screen** | Halaman yang muncul ketika lisensi expired/suspended/revoked |
| **Online Validation** | HTTP call ke License Monitor untuk validasi real-time |
| **Clock Tampering** | Upaya memundurkan waktu sistem untuk memperpanjang masa lisensi |

---

*Dokumen ini adalah PRD untuk `devwebs01/laravel-licensing-client` v1.0.0*  
*Filosofi: "Simple licensing that is difficult enough to abuse, not impossible to crack."*
