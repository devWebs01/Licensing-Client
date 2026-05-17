<?php

namespace DevWebs01\LicensingClient\Commands;

use DevWebs01\LicensingClient\Exceptions\ServerUnreachableException;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Console\Command;

final class LicenseSyncCommand extends Command
{
    protected $signature = 'license:sync';

    protected $description = 'Syncronize license dengan server licensing';

    public function __construct(
        private readonly LicenseClientService $licenseService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->info('Syncronisasi lisensi...');

        try {
            $result = $this->licenseService->sync();

            if ($result->valid) {
                $this->components->success('Lisensi valid. Offline until: '.($result->offlineUntil ?? '-'));

                return self::SUCCESS;
            }

            $this->components->warn('Lisensi tidak valid: '.($result->message ?? 'Unknown'));

            return self::FAILURE;
        } catch (ServerUnreachableException) {
            $this->components->warn('Server lisensi tidak reachable. Menggunakan cache lokal.');

            return self::SUCCESS;
        }
    }
}
