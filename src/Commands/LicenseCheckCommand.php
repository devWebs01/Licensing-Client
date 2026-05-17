<?php

namespace DevWebs01\LicensingClient\Commands;

use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class LicenseCheckCommand extends Command
{
    protected $signature = 'license:check';

    protected $description = 'Periksa konfigurasi dan konektivitas lisensi';

    public function __construct(
        private readonly LicenseClientService $licenseService,
        private readonly LicenseCacheService $cacheService,
        private readonly FingerprintCollector $fingerprint,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $passed = true;

        $this->components->twoColumnDetail('<fg=yellow>Memeriksa Konfigurasi</>', '');

        $serverUrl = config('licensing-client.server_url');
        if ($serverUrl) {
            $this->components->twoColumnDetail('Server URL', $serverUrl);
        } else {
            $this->components->twoColumnDetail('Server URL', '<fg=red>TIDAK DIKONFIGURASI</>');
            $passed = false;
        }

        $apiKey = config('licensing-client.api_key');
        if ($apiKey) {
            $this->components->twoColumnDetail('API Key', substr($apiKey, 0, 8).'...');
        } else {
            $this->components->twoColumnDetail('API Key', '<fg=red>TIDAK DIKONFIGURASI</>');
            $passed = false;
        }

        $apiSecret = config('licensing-client.api_secret');
        if ($apiSecret) {
            $this->components->twoColumnDetail('API Secret', substr($apiSecret, 0, 4).'...');
        } else {
            $this->components->twoColumnDetail('API Secret', '<fg=red>TIDAK DIKONFIGURASI</>');
            $passed = false;
        }

        $this->components->twoColumnDetail('Environment', config('licensing-client.environment', 'production'));

        $this->components->twoColumnDetail('', '');

        $this->components->twoColumnDetail('<fg=yellow>Memeriksa Koneksi Server</>', '');

        try {
            $response = Http::timeout(5)
                ->get(rtrim($serverUrl, '/').'/api/v1/health');

            if ($response->successful()) {
                $this->components->twoColumnDetail('Server Health', '<fg=green>OK</>');
            } else {
                $this->components->twoColumnDetail('Server Health', '<fg=red>Gagal ('.$response->status().')</>');
                $passed = false;
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('Server Health', '<fg=red>Tidak dapat dijangkau</>');
            $this->components->twoColumnDetail('Error', $e->getMessage());
            $passed = false;
        }

        $this->components->twoColumnDetail('', '');

        $this->components->twoColumnDetail('<fg=yellow>Memeriksa Cache</>', '');

        $hasToken = $this->cacheService->hasToken();
        $this->components->twoColumnDetail('Token Tersimpan', $hasToken ? '<fg=green>Ya</>' : '<fg=yellow>Tidak</>');

        if ($hasToken) {
            $token = $this->cacheService->retrieveToken();
            if ($token) {
                $offlineUntil = $token['offline_until'] ?? null;
                $cachedAt = $token['cached_at'] ?? null;
                $this->components->twoColumnDetail('Offline Until', $offlineUntil ?? '-');
                $this->components->twoColumnDetail('Cached At', $cachedAt ?? '-');
            } else {
                $this->components->twoColumnDetail('Token Integrity', '<fg=red>Rusak</>');
                $passed = false;
            }
        }

        $this->components->twoColumnDetail('', '');

        $this->components->twoColumnDetail('<fg=yellow>Memeriksa Perangkat</>', '');
        $fingerprint = $this->fingerprint->fingerprint();
        $deviceData = $this->fingerprint->collectData();
        $this->components->twoColumnDetail('Fingerprint', $fingerprint);
        $this->components->twoColumnDetail('Hostname', $deviceData['hostname']);
        $this->components->twoColumnDetail('OS', $deviceData['os'].' '.$deviceData['kernel']);

        $this->line('');

        if ($passed) {
            $this->components->success('Semua pemeriksaan berhasil.');

            return self::SUCCESS;
        }

        $this->components->error('Beberapa pemeriksaan gagal. Perbaiki konfigurasi dan coba lagi.');

        return self::FAILURE;
    }
}
