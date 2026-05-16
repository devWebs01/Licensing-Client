<?php

namespace DevWebs01\LicensingClient\Components;

use DevWebs01\LicensingClient\Facades\LicenseClient;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class CountdownWarning extends Component
{
    public int $daysRemaining;
    public ?string $offlineUntil;
    public bool $shouldShow = false;

    public function __construct()
    {
        $info = LicenseClient::info();

        $this->daysRemaining = $info->graceDaysRemaining;
        $this->offlineUntil = $info->offlineUntil;

        if ($info->isWithinGracePeriod && $this->daysRemaining <= 3 && $this->daysRemaining > 0) {
            $this->shouldShow = true;
        }
    }

    public function render(): View
    {
        return view('licensing::components.countdown-warning');
    }
}
