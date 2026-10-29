@php
    /**
     * @var string $domain
     * @var string|null $email
     * @var array{status: string, found: list<string>, expected: list<string>, domain?: string}|null $check
     */
@endphp

<div data-test="wizard-domain" style="display: flex; flex-direction: column; gap: 12px; font-size: 14px;">
    @if ($domain === '')
        <p data-test="wizard-no-domain">{{ __('admin.games.wizard.no_domain') }}</p>
    @else
        <p>{{ __('admin.games.wizard.domain_intro', ['domain' => $domain]) }}</p>

        <div>
            <x-filament::button color="gray" size="sm" wire:click="checkDns" data-test="check-dns">{{ __('admin.domain.check_dns') }}</x-filament::button>
        </div>

        @include('filament.games.dns-result', ['check' => $check, 'domain' => $domain])

        @if ($email === null)
            <p data-test="wizard-no-email" style="color: #f59e0b;">{{ __('admin.domain.no_email') }}</p>
        @endif
    @endif
</div>
