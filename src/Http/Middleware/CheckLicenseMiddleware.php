<?php

namespace DevWebs01\LicensingClient\Http\Middleware;

use Closure;
use DevWebs01\LicensingClient\Enums\LicenseStatus;
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

        $info = $this->licenseService->status();

        if ($info->isValid && ! $info->status->isBlocking()) {
            if ($info->isWithinGracePeriod && $info->graceDaysRemaining <= 3) {
                if ($request->hasSession()) {
                    $request->session()->flash(
                        'license_warning',
                        "Lisensi akan expired dalam {$info->graceDaysRemaining} hari. Hubungi admin."
                    );
                }
            }

            return $next($request);
        }

        if ($request->wantsJson()) {
            return response()->json(['error' => 'License invalid', 'reason' => $info->status->value], 403);
        }

        if ($info->status === LicenseStatus::NotActivated) {
            return redirect()->route('licensing.activate');
        }

        return redirect()->route('licensing.locked', ['reason' => $info->status->value]);
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
