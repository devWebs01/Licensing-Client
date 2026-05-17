<?php

namespace DevWebs01\LicensingClient\Http\Middleware;

use Closure;
use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Exceptions\ClockDriftDetectedException;
use DevWebs01\LicensingClient\Exceptions\LicenseNotActivatedException;
use DevWebs01\LicensingClient\Exceptions\ServerUnreachableException;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLicenseMiddleware
{
    public function __construct(
        private readonly LicenseClientService $licenseService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isDevelopmentBypass()) {
            return $next($request);
        }

        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }

        return $this->checkCache($request) ?? $next($request);
    }

    private function checkCache(Request $request): ?Response
    {
        try {
            $info = $this->licenseService->status();

            if ($info->isValid && $info->status->isBlocking() === false) {
                if ($info->isWithinGracePeriod && $info->graceDaysRemaining <= 3) {
                    session()->flash(
                        'license_warning',
                        "Lisensi akan expired dalam {$info->graceDaysRemaining} hari. Hubungi admin."
                    );
                }

                return null;
            }

            if ($info->status === LicenseStatus::NotActivated) {
                return $this->redirectToWizard();
            }

            if ($info->status->isBlocking()) {
                return $this->lockResponse($info->status->value);
            }

            return $this->validateOnline();
        } catch (LicenseNotActivatedException) {
            return $this->redirectToWizard();
        }
    }

    private function validateOnline(): ?Response
    {
        try {
            $result = $this->licenseService->validateOnline();

            if ($result->valid) {
                return null;
            }

            return $this->validateGracePeriod();
        } catch (ServerUnreachableException|ClockDriftDetectedException) {
            return $this->validateGracePeriod();
        }
    }

    private function validateGracePeriod(): ?Response
    {
        try {
            $result = $this->licenseService->validateOffline();

            if ($result->valid) {
                $daysRemaining = $this->licenseService->status()->graceDaysRemaining;

                if ($daysRemaining <= 3) {
                    session()->flash(
                        'license_warning',
                        "Lisensi akan expired dalam {$daysRemaining} hari. Hubungi admin."
                    );
                }

                return null;
            }

            return $this->lockResponse('grace_expired');
        } catch (LicenseNotActivatedException|ClockDriftDetectedException) {
            return $this->redirectToWizard();
        }
    }

    private function lockResponse(string $reason): Response
    {
        return redirect()->route('licensing.locked', ['reason' => $reason]);
    }

    private function redirectToWizard(): Response
    {
        return redirect()->route('licensing.activate');
    }

    private function isExcludedRoute(Request $request): bool
    {
        $excluded = config('licensing-client.excluded_routes', []);

        foreach ($excluded as $route) {
            if ($request->is($route) || $request->routeIs($route)) {
                return true;
            }
        }

        return false;
    }

    private function isDevelopmentBypass(): bool
    {
        if ((bool) config('licensing-client.dev_bypass', false)) {
            return true;
        }

        $env = config('licensing-client.environment', 'production');

        return ! in_array($env, ['production', 'license'], true);
    }
}
