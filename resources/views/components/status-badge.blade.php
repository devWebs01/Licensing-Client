@php
    $colors = [
        'active' => ['bg' => '#dcfce7', 'text' => '#166534'],
        'grace_warning' => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'suspended' => ['bg' => '#fef2f2', 'text' => '#991b1b'],
        'expired' => ['bg' => '#fef2f2', 'text' => '#991b1b'],
        'revoked' => ['bg' => '#fef2f2', 'text' => '#991b1b'],
        'locked' => ['bg' => '#fef2f2', 'text' => '#991b1b'],
        'not_activated' => ['bg' => '#f3f4f6', 'text' => '#6b7280'],
        'pending_approval' => ['bg' => '#eff6ff', 'text' => '#1e40af'],
    ];
    $color = $colors[$status->value] ?? ['bg' => '#f3f4f6', 'text' => '#6b7280'];
@endphp
<span style="display:inline-flex;align-items:center;padding:0.25rem 0.75rem;border-radius:9999px;font-size:0.75rem;font-weight:600;background:{{ $color['bg'] }};color:{{ $color['text'] }};">
    {{ $label }}
</span>
