<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aktivasi Lisensi</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 2rem; width: 100%; max-width: 480px; margin: 1rem; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        p { color: #6b7280; margin-bottom: 1.5rem; line-height: 1.5; }
        .device-info { background: #f9fafb; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; }
        .device-info dt { font-weight: 600; color: #374151; margin-top: 0.5rem; }
        .device-info dt:first-child { margin-top: 0; }
        .device-info dd { color: #6b7280; word-break: break-all; }
        label { display: block; font-weight: 600; margin-bottom: 0.5rem; font-size: 0.875rem; }
        input { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; font-family: monospace; text-align: center; letter-spacing: 2px; }
        input:focus { outline: none; border-color: #3b82f6; ring: 2px solid #3b82f6; }
        .error { color: #ef4444; font-size: 0.875rem; margin-top: 0.5rem; }
        button { width: 100%; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 1rem; }
        button:hover { background: #2563eb; }
        .approval-box { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 1.5rem; text-align: center; margin: 1rem 0; }
        .approval-box .code { font-size: 2rem; font-family: monospace; font-weight: 700; letter-spacing: 4px; color: #92400e; margin: 1rem 0; }
        .info-box { background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; font-size: 0.875rem; color: #1e40af; }
        .loading { display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .spinner { width: 40px; height: 40px; border: 4px solid #e5e7eb; border-top-color: #3b82f6; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .step { display: none; }
        .step.active { display: block; }
    </style>
</head>
<body>
    <div class="card">
        @php $hasConfig = config('licensing-client.server_url') && config('licensing-client.api_key'); @endphp

        @if (!$hasConfig)
            <h1>Setup Lisensi</h1>
            <div class="info-box" style="background:#fef3c7;border-color:#f59e0b;color:#92400e;">
                <p><strong>Lisensi belum dikonfigurasi.</strong></p>
                <p style="margin-top:0.5rem;">Tambahkan konfigurasi berikut di file <code style="background:#fef9c3;padding:0.125rem 0.375rem;border-radius:4px;font-size:0.75rem;">.env</code>:</p>
                <pre style="background:#fffbeb;padding:0.75rem;border-radius:6px;margin-top:0.5rem;font-size:0.75rem;overflow-x:auto;">LICENSING_SERVER_URL=http://license-monitor.test
LICENSING_API_KEY=your_api_key
LICENSING_API_SECRET=your_api_secret</pre>
                <p style="margin-top:0.75rem;">Atau jalankan <code style="background:#fef9c3;padding:0.125rem 0.375rem;border-radius:4px;font-size:0.75rem;">php artisan license:check</code> untuk diagnose.</p>
            </div>
            <p style="text-align:center;font-size:0.875rem;color:#6b7280;">Setelah dikonfigurasi, refresh halaman ini.</p>

        @elseif (session('requires_approval'))
            <h1>Menunggu Approval</h1>
            <div class="approval-box">
                <p>Kode Aktivasi Anda:</p>
                <div class="code">{{ session('activation_code') }}</div>
                <p style="font-size:0.875rem;">Berikan kode ini ke admin untuk di-approve.</p>
            </div>
            <p style="text-align:center;font-size:0.875rem;">Halaman ini akan auto-refresh setiap 30 detik.</p>
            <script>setTimeout(function(){ location.reload(); }, 30000);</script>

        @elseif (isset($requires_approval) && $requires_approval)
            <h1>Menunggu Approval</h1>
            <div class="approval-box">
                <p>Kode Aktivasi Anda:</p>
                <div class="code">{{ $activation_code }}</div>
                <p style="font-size:0.875rem;">Berikan kode ini ke admin untuk di-approve.</p>
            </div>
            <div id="poll-status" style="text-align:center;font-size:0.875rem;color:#6b7280;">Memeriksa status aktivasi...</div>
            <script>
            (function() {
                var pollUrl = '{{ route('licensing.poll') }}';
                var check = function() {
                    fetch(pollUrl)
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.approved) {
                                document.getElementById('poll-status').innerText = 'Aktivasi disetujui! Mengarahkan...';
                                window.location.href = '/';
                            } else {
                                setTimeout(check, 5000);
                            }
                        })
                        .catch(function() {
                            setTimeout(check, 10000);
                        });
                };
                setTimeout(check, 3000);
            })();
            </script>

        @elseif(isset($status))
            <h1>Status Lisensi</h1>
            <div class="info-box">
                <p><strong>Status:</strong> {{ $status->status->label() }}</p>
                <p><strong>Valid:</strong> {{ $status->isValid ? 'Ya' : 'Tidak' }}</p>
                @if($status->product)
                    <p><strong>Product:</strong> {{ $status->product }}</p>
                @endif
                @if($status->graceDaysRemaining > 0)
                    <p><strong>Grace Period:</strong> {{ $status->graceDaysRemaining }} hari</p>
                @endif
            </div>
            <button onclick="window.location='/'">Kembali ke Dashboard</button>

        @else
            <div id="step1" class="step active">
                <h1>Selamat Datang</h1>
                <p>Aplikasi ini memerlukan lisensi untuk dapat digunakan. Silakan mulai proses aktivasi.</p>
                <button onclick="showStep(2)">Mulai Aktivasi</button>
            </div>

            <div id="step2" class="step">
                <h1>Deteksi Perangkat</h1>
                <p>Berikut adalah informasi perangkat Anda:</p>
                <dl class="device-info">
                    <dt>Hostname</dt>
                    <dd>{{ php_uname('n') }}</dd>
                    <dt>Sistem Operasi</dt>
                    <dd>{{ php_uname('s') }} {{ php_uname('r') }}</dd>
                    <dt>Fingerprint</dt>
                    <dd style="font-family:monospace;font-size:0.75rem;">{{ app(\DevWebs01\LicensingClient\Services\FingerprintCollector::class)->fingerprint() }}</dd>
                </dl>
                <button onclick="showStep(3)">Lanjutkan</button>
                <button onclick="showStep(1)" style="background:#6b7280;margin-top:0.5rem;">Kembali</button>
            </div>

            <div id="step3" class="step">
                <h1>Masukkan Lisensi</h1>
                <p>Masukkan license key yang Anda dapatkan dari admin.</p>

                @if(isset($errors) && $errors->any())
                    <div class="error">{{ $errors->first('license_key') }}</div>
                @endif

                <form method="POST" action="{{ route('licensing.activate') }}">
                    @csrf
                    <label for="license_key">License Key</label>
                    <input type="text" id="license_key" name="license_key" placeholder="XXXX-XXXX-XXXX-XXXX" pattern="[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}" maxlength="19" autocomplete="off" required>
                    <button type="submit">Aktivasi</button>
                </form>
                <button onclick="showStep(2)" style="background:#6b7280;margin-top:0.5rem;">Kembali</button>
            </div>
        @endif
    </div>

    <script>
        function showStep(num) {
            document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
            document.getElementById('step' + num).classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            var input = document.getElementById('license_key');
            if (input) {
                input.addEventListener('input', function(e) {
                    var val = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                    var parts = [];
                    for (var i = 0; i < val.length && parts.length < 4; i += 4) {
                        parts.push(val.substr(i, 4));
                    }
                    this.value = parts.join('-');
                });
            }
        });
    </script>
</body>
</html>
