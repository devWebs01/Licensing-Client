@if($shouldShow)
    <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; color: #92400e;">
        <strong>Peringatan Lisensi:</strong>
        Lisensi akan expired dalam <strong>{{ $daysRemaining }} hari</strong>.
        @if($offlineUntil)
            <br><small>Berlaku hingga: {{ \Illuminate\Support\Carbon::parse($offlineUntil)->format('d M Y H:i') }}</small>
        @endif
    </div>
@endif
