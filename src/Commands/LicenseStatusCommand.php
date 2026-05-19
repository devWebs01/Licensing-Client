<?php

declare(strict_types=1);

namespace DevWebs01\LicensingClient\Commands;

use DevWebs01\LicensingClient\Services\FingerprintCollector;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Console\Command;

final class LicenseStatusCommand extends Command
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

        $this->components->twoColumnDetail('Status', $info->status->label());
        $this->components->twoColumnDetail('Product', $info->product ?? '-');
        $this->components->twoColumnDetail('License Key', $this->maskLicenseKey());
        $this->components->twoColumnDetail('Expires At', $info->expiresAt ?? '-');
        $this->components->twoColumnDetail('Offline Until', $info->offlineUntil ?? '-');
        $this->components->twoColumnDetail('Cache Age', $this->getCacheAge($info->cachedAt));
        $this->components->twoColumnDetail('Device', "{$fingerprint} ({$this->getHostname()})");
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

    private function getHostname(): string
    {
        try {
            return php_uname('n');
        } catch (\Throwable) {
            return '-';
        }
    }
}
