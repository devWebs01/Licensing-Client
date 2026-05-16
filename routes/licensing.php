<?php

use DevWebs01\LicensingClient\Facades\LicenseClient;
use Illuminate\Support\Facades\Route;

$prefix = config('licensing-client.route_prefix', 'licensing');

Route::prefix($prefix)->group(function () {
    Route::get('/activate', function () {
        return view('licensing::activate');
    })->name('licensing.activate');

    Route::post('/activate', function (\Illuminate\Http\Request $request) {
        $key = $request->input('license_key');

        if (empty($key)) {
            return back()->withErrors(['license_key' => 'License key wajib diisi']);
        }

        $result = LicenseClient::activate($key);

        if ($result->success) {
            if ($result->requiresApproval) {
                session(['activation_code' => $result->activationCode, 'pending_key' => $key]);

                return view('licensing::activate', [
                    'requires_approval' => true,
                    'activation_code' => $result->activationCode,
                ]);
            }

            return redirect('/')->with('success', 'Aktivasi berhasil!');
        }

        return back()->withErrors(['license_key' => $result->message ?? 'Gagal aktivasi']);
    });

    Route::get('/status', function () {
        $info = LicenseClient::info();

        return view('licensing::activate', [
            'status' => $info,
        ]);
    })->name('licensing.status');

    Route::get('/locked', function (\Illuminate\Http\Request $request) {
        return view('licensing::locked', [
            'reason' => $request->query('reason', 'unknown'),
        ]);
    })->name('licensing.locked');

    Route::get('/poll', function (\Illuminate\Http\Request $request) {
        $code = session('activation_code');

        if ($code === null) {
            return response()->json(['approved' => false, 'error' => 'No pending activation']);
        }

        $approved = LicenseClient::verifyActivation($code);

        if ($approved) {
            session()->forget(['activation_code', 'pending_key']);

            return response()->json(['approved' => true]);
        }

        return response()->json(['approved' => false]);
    })->name('licensing.poll');

    Route::post('/retry', function () {
        LicenseClient::refresh();

        return redirect()->back();
    })->name('licensing.retry');
});
