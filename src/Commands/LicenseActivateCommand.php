<?php

namespace DevWebs01\LicensingClient\Commands;

use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Console\Command;

final class LicenseActivateCommand extends Command
{
    protected $signature = 'license:activate {key : License key (format: XXXX-XXXX-XXXX-XXXX)}';

    protected $description = 'Aktivasi license key untuk aplikasi ini';

    public function __construct(
        private readonly LicenseClientService $licenseService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $key = $this->argument('key');

        $this->components->info('Mengaktivasi lisensi...');

        $result = $this->licenseService->activate($key);

        if ($result->success) {
            if ($result->requiresApproval) {
                $this->components->warn('Aktivasi memerlukan approval admin.');
                $this->line("Kode aktivasi: {$result->activationCode}");
                $this->line("Kadaluwarsa: {$result->offlineUntil}");

                return self::SUCCESS;
            }

            $this->components->info('Lisensi berhasil diaktivasi!');
            $this->line("Offline until: {$result->offlineUntil}");

            return self::SUCCESS;
        }

        $this->components->error($result->message ?? 'Gagal aktivasi lisensi');

        return self::FAILURE;
    }
}
