<?php

declare(strict_types=1);

namespace DevWebs01\LicensingClient\Http\Controllers;

use DevWebs01\LicensingClient\Http\Requests\ActivateRequest;
use DevWebs01\LicensingClient\Services\LicenseClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

final class LicenseWizardController extends Controller
{
    public function __construct(
        private readonly LicenseClientService $licenseService,
    ) {}

    public function showActivate(): View
    {
        /** @phpstan-ignore argument.type */
        return view('licensing::activate');
    }

    public function activate(ActivateRequest $request): RedirectResponse|View
    {
        $result = $this->licenseService->activate($request->validated('license_key'));

        if ($result->success) {
            if ($result->requiresApproval) {
                session([
                    'activation_code' => $result->activationCode,
                    'pending_key' => $request->validated('license_key'),
                ]);

                /** @phpstan-ignore argument.type */
                return view('licensing::activate', [
                    'requires_approval' => true,
                    'activation_code' => $result->activationCode,
                ]);
            }

            return redirect('/')->with('success', 'Aktivasi berhasil!');
        }

        return back()->withErrors(['license_key' => $result->message ?? 'Gagal aktivasi']);
    }

    public function showStatus(): View
    {
        /** @phpstan-ignore argument.type */
        return view('licensing::activate', [
            'status' => $this->licenseService->status(),
        ]);
    }

    public function showLocked(Request $request): View
    {
        /** @phpstan-ignore argument.type */
        return view('licensing::locked', [
            'reason' => $request->query('reason', 'unknown'),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $code = session('activation_code');

        if ($code === null) {
            return response()->json(['approved' => false, 'error' => 'No pending activation']);
        }

        $approved = $this->licenseService->verifyActivation($code);

        if ($approved) {
            session()->forget(['activation_code', 'pending_key']);

            return response()->json(['approved' => true]);
        }

        return response()->json(['approved' => false]);
    }

    public function retry(): RedirectResponse
    {
        $this->licenseService->refresh();

        return redirect()->back();
    }
}
