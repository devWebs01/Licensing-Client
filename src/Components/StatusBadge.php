<?php

namespace DevWebs01\LicensingClient\Components;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Facades\LicenseClient;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class StatusBadge extends Component
{
    public LicenseStatus $status;
    public string $label;
    public bool $isValid;

    public function __construct()
    {
        $info = LicenseClient::info();

        $this->status = $info->status;
        $this->label = $info->status->label();
        $this->isValid = $info->isValid;
    }

    public function render(): View
    {
        return view('licensing::components.status-badge');
    }
}
