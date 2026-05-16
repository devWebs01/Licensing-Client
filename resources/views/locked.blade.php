<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akses Diblokir</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fef2f2; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 2rem; width: 100%; max-width: 480px; margin: 1rem; text-align: center; }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        p { color: #6b7280; margin-bottom: 1.5rem; line-height: 1.5; }
        .reason-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; }
        .reason-box p { color: #991b1b; margin-bottom: 0; }
        .actions { display: flex; flex-direction: column; gap: 0.75rem; }
        .btn-primary { padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { padding: 0.75rem; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn-secondary:hover { background: #4b5563; }
        .admin-contact { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; font-size: 0.875rem; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔒</div>
        <h1>Akses Diblokir</h1>
        <div class="reason-box">
            @switch($reason ?? 'unknown')
                @case('expired')
                @case('grace_expired')
                    <p>Lisensi Anda telah kedaluwarsa. Silakan hubungi admin untuk perpanjangan.</p>
                    @break
                @case('suspended')
                    <p>Lisensi Anda ditangguhkan. Hubungi admin untuk informasi lebih lanjut.</p>
                    @break
                @case('revoked')
                    <p>Lisensi Anda telah dicabut. Hubungi admin untuk informasi lebih lanjut.</p>
                    @break
                @default
                    <p>Lisensi tidak valid atau belum diaktivasi.</p>
            @endswitch
        </div>
        <div class="actions">
            <form method="POST" action="{{ route('licensing.retry') }}">
                @csrf
                <button type="submit" class="btn-primary">Coba Validasi Ulang</button>
            </form>
            <a href="{{ route('licensing.activate') }}" class="btn-secondary">Aktivasi Ulang</a>
        </div>
        <div class="admin-contact">
            Hubungi: <strong>{{ config('licensing-client.admin_contact', 'admin@company.com') }}</strong>
        </div>
    </div>
</body>
</html>
