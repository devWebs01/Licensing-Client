<?php

namespace DevWebs01\LicensingClient\Components;

use DevWebs01\LicensingClient\Enums\LicenseStatus;
use DevWebs01\LicensingClient\Facades\LicenseClient;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class LockedScreen extends Component
{
    public LicenseStatus $status;
    public string $label;
    public string $adminContact;
    public string $reason;

    public function __construct()
    {
        $info = LicenseClient::info();

        $this->status = $info->status;
        $this->label = $info->status->label();
        $this->adminContact = config('licensing-client.admin_contact', 'admin@company.com');
        $this->reason = $this->status->value;
    }

    public function render(): View
    {
        return view('licensing::components.locked-screen');
    }
}
