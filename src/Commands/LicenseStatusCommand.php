<?php

namespace DevWebs01\LicensingClient\Commands;

use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Services\LicenseCacheService;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Console\Command;

class LicenseStatusCommand extends Command
{
    protected $signature = 'license:status';
    protected $description = 'Tampilkan status lisensi saat ini';

    public function __construct(
        private readonly LicenseClientService $licenseService,
        private readonly FingerprintCollector $fingerprint,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $info = $this->licenseService->status();
        $fingerprint = $this->fingerprint->fingerprint();
        $deviceData = $this->fingerprint->collectData();

        $this->components->twoColumnDetail('Status', $info->status->label());
        $this->components->twoColumnDetail('Product', $info->product ?? '-');
        $this->components->twoColumnDetail('License Key', $this->maskLicenseKey());
        $this->components->twoColumnDetail('Expires At', $info->offlineUntil ?? '-');
        $this->components->twoColumnDetail('Offline Until', $info->offlineUntil ?? '-');
        $this->components->twoColumnDetail('Cache Age', $this->getCacheAge($info->cachedAt));
        $this->components->twoColumnDetail('Device', "{$fingerprint} ({$deviceData['hostname']})");
        $this->components->twoColumnDetail('Features', $this->getFeatureFlags());
        $this->components->twoColumnDetail('Grace Days', (string) $info->graceDaysRemaining);

        return self::SUCCESS;
    }

    private function maskLicenseKey(): string
    {
        $key = config('licensing-client.license_key', '');

        if (empty($key)) {
            return '-';
        }

        $parts = explode('-', $key);

        if (count($parts) === 4) {
            $parts[1] = '****';
            $parts[2] = '****';
        }

        return implode('-', $parts);
    }

    private function getCacheAge(?string $cachedAt): string
    {
        if ($cachedAt === null) {
            return '-';
        }

        try {
            $diff = now()->diffInHours($cachedAt);

            return "{$diff} hours";
        } catch (\Throwable) {
            return '-';
        }
    }

    private function getFeatureFlags(): string
    {
        $token = app(LicenseCacheService::class)->retrieveToken();

        $features = $token['features'] ?? [];

        return empty($features) ? '-' : implode(', ', $features);
    }
}
