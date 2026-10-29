@php
    /**
     * @var array{status: string, found: list<string>, expected: list<string>, domain?: string}|null $check
     * @var string $domain
     */
    $color = ['ok' => '#22c55e', 'wrong' => '#ef4444', 'missing' => '#f59e0b', 'invalid' => '#ef4444', 'unknown' => '#f59e0b'][$check['status'] ?? ''] ?? '#888';
@endphp

@if ($check)
    <div data-test="dns-result" data-status="{{ $check['status'] }}" style="border-left: 3px solid {{ $color }}; padding: 6px 12px; display: flex; flex-direction: column; gap: 4px; font-size: 14px;">
        <strong>{{ __('admin.domain.dns.'.$check['status']) }}</strong>
        @if (($check['domain'] ?? $domain) !== $domain)
            <span style="opacity: .7;">{{ __('admin.domain.dns.other_domain', ['domain' => $check['domain']]) }}</span>
        @endif
        @if ($check['expected'] !== [])
            <span style="opacity: .8;">{{ __('admin.domain.dns.expected', ['addresses' => implode(', ', $check['expected'])]) }}</span>
        @endif
        @if ($check['found'] !== [])
            <span style="opacity: .8;">{{ __('admin.domain.dns.found', ['addresses' => implode(', ', $check['found'])]) }}</span>
        @endif
        @if (in_array($check['status'], ['wrong', 'missing'], true) && $check['expected'] !== [])
            <span>{{ __('admin.domain.dns.instruction', ['domain' => $domain, 'addresses' => implode(', ', $check['expected'])]) }}</span>
        @endif
    </div>
@endif
