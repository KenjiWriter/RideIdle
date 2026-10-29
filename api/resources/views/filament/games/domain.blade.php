@php
    /**
     * @var \App\Models\Game|null $game
     * @var string $domain
     * @var \App\Models\DomainJob|null $job
     * @var array<string, mixed>|null $check
     * @var string|null $email
     * @var string|null $editUrl
     * @var bool $busy
     * @var bool $workerSlow
     * @var \Illuminate\Support\Carbon|null $expires
     * @var int|null $daysLeft
     */
    $statusColor = ['requested' => 'warning', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];
    $box = 'border: 1px solid rgba(128, 128, 128, .3); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 10px;';
    $muted = 'font-size: 13px; opacity: .65;';
@endphp

<x-filament-panels::page>
    {{-- While the server is at it, the page asks it how it goes. --}}
    <div @if ($busy) wire:poll.5s @endif style="display: flex; flex-direction: column; gap: 16px;" data-test="domain-page">
        @if ($domain === '')
            <div style="{{ $box }}" data-test="no-domain">
                <strong>{{ __('admin.domain.domain_none') }}</strong>
                @if ($editUrl)
                    <div><x-filament::button tag="a" :href="$editUrl" size="sm">{{ __('admin.domain.edit_game') }}</x-filament::button></div>
                @endif
            </div>
        @else
            <div style="{{ $box }}">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div>
                        <div style="{{ $muted }}">{{ __('admin.games.domain') }}</div>
                        <div style="font-size: 20px; font-weight: 600;" data-test="domain-name">{{ $domain }}</div>
                    </div>
                    <x-filament::badge :color="$game?->isLive() ? 'success' : 'warning'">{{ __('admin.games.status_'.($game?->isLive() ? 'live' : 'draft')) }}</x-filament::badge>
                </div>
                @unless ($game?->isLive())
                    <div style="{{ $muted }}">{{ __('admin.domain.draft_hint') }}</div>
                @endunless
            </div>

            <div style="{{ $box }}">
                <strong>{{ __('admin.domain.dns.title') }}</strong>
                <div style="{{ $muted }}">{{ __('admin.domain.dns.hint') }}</div>
                <div><x-filament::button color="gray" size="sm" wire:click="checkDns" data-test="check-dns">{{ __('admin.domain.check_dns') }}</x-filament::button></div>
                @include('filament.games.dns-result', ['check' => $check, 'domain' => $domain])
            </div>

            <div style="{{ $box }}">
                <strong>{{ __('admin.domain.certificate.title') }}</strong>
                @if ($expires)
                    @php($state = $daysLeft < 0 ? 'expired' : ($daysLeft <= 14 ? 'soon' : 'valid'))
                    <div data-test="certificate" data-state="{{ $state }}" style="color: {{ ['valid' => '#22c55e', 'soon' => '#f59e0b', 'expired' => '#ef4444'][$state] }};">
                        {{ __('admin.domain.certificate.'.$state, ['date' => $expires->format('Y-m-d'), 'days' => max(0, $daysLeft)]) }}
                    </div>
                @else
                    <div style="{{ $muted }}" data-test="certificate" data-state="none">{{ __('admin.domain.certificate.none') }}</div>
                @endif

                @if ($email === null)
                    <div data-test="no-email" style="color: #f59e0b; font-size: 14px;">{{ __('admin.domain.no_email') }}</div>
                @endif

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <x-filament::button wire:click="requestProvision" :disabled="$busy || $email === null" data-test="request-provision">{{ __('admin.domain.request_provision') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="requestCheck" :disabled="$busy" data-test="request-check">{{ __('admin.domain.request_check') }}</x-filament::button>
                </div>
                <div style="{{ $muted }}">{{ __('admin.domain.request_hint') }}</div>
            </div>

            <div style="{{ $box }}" data-test="last-job">
                <strong>{{ __('admin.domain.job.title') }}</strong>
                @if ($job)
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <x-filament::badge :color="$statusColor[$job->status] ?? 'gray'" data-test="job-status">{{ __('admin.domain.job.status.'.$job->status) }}</x-filament::badge>
                        <span>{{ __('admin.domain.job.action.'.$job->action) }}</span>
                    </div>
                    <div style="{{ $muted }}">
                        {{ __('admin.domain.job.by', ['name' => $job->requester?->name ?? '-', 'when' => $job->created_at?->format('Y-m-d H:i')]) }}
                        @if ($job->finished_at)
                            · {{ __('admin.domain.job.finished', ['when' => $job->finished_at->format('Y-m-d H:i')]) }}
                        @endif
                        @if ($job->exit_code !== null)
                            · {{ __('admin.domain.job.exit', ['code' => $job->exit_code]) }}
                        @endif
                    </div>
                    @if ($workerSlow)
                        <div data-test="worker-slow" style="color: #f59e0b; font-size: 14px;">{{ __('admin.domain.job.waiting') }}</div>
                    @endif
                    @if (filled($job->log))
                        <details data-test="job-log">
                            <summary style="cursor: pointer;">{{ __('admin.domain.job.log') }}</summary>
                            <pre style="margin-top: 8px; max-height: 320px; overflow: auto; font-size: 12px; white-space: pre-wrap;">{{ $job->log }}</pre>
                        </details>
                    @endif
                @else
                    <div style="{{ $muted }}" data-test="no-job">{{ __('admin.domain.job.none') }}</div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
