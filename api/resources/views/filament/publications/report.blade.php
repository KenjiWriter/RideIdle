@php
    $report = $publication->report ?? [];
    $section = 'display: grid; gap: 0.35rem; margin-bottom: 1.25rem;';
    $heading = 'font-weight: 600; margin: 0;';
    $row = 'display: flex; justify-content: space-between; gap: 1rem;';
    $muted = 'opacity: 0.8;';
@endphp

<div style="display: grid;">
    <div style="{{ $section }}">
        <div style="{{ $row }}"><span>{{ __('admin.publications.status') }}</span><strong>{{ __("admin.publications.statuses.{$publication->status}") }}@if ($live) · {{ __('admin.packs.live') }}@endif</strong></div>
        @if (! empty($report['failed_step']))
            <div style="{{ $row }}"><span>{{ __('admin.publications.stopped_at') }}</span><strong>{{ __("admin.publications.steps.{$report['failed_step']}") }}</strong></div>
        @endif
        @if ($publication->pack)
            <div style="{{ $row }}"><span>{{ __('admin.packs.one') }}</span><code>{{ $publication->pack->number }} · {{ \Illuminate\Support\Str::limit($publication->pack->checksum, 12, '') }}</code></div>
        @endif
        <div style="{{ $row }}"><span>{{ __('admin.publications.by') }}</span><span>{{ $publication->requestedBy?->name ?? '-' }}</span></div>
        @if ($publication->note)
            <div style="{{ $row }}"><span>{{ __('admin.publications.note') }}</span><span>{{ $publication->note }}</span></div>
        @endif
    </div>

    @if (! empty($report['errors']))
        <div style="{{ $section }}">
            <h3 style="{{ $heading }}">{{ __('admin.publications.errors') }}</h3>
            @foreach ($report['errors'] as $issue)
                <div><code>{{ trim(($issue['file'] ?? '').' '.($issue['pointer'] ?? '')) }}</code> {{ $issue['message'] }}</div>
            @endforeach
        </div>
    @endif

    @if (! empty($report['gates']))
        <div style="{{ $section }}">
            <h3 style="{{ $heading }}">{{ __('admin.publications.gates') }}</h3>
            @foreach ($report['gates'] as $gate)
                <div style="{{ $row }}">
                    <code>{{ $gate['name'] }}</code>
                    <strong>{{ $gate['passed'] ? __('admin.publications.passed') : __('admin.publications.not_passed') }} <span style="{{ $muted }}">({{ round($gate['ms'] / 1000, 1) }} s)</span></strong>
                </div>
                @if (! $gate['passed'] && ! empty($gate['excerpt']))
                    <pre style="max-height: 14rem; overflow: auto; font-size: 0.75rem; padding: 0.5rem; border-radius: 0.375rem; background: rgba(127, 127, 127, 0.12); white-space: pre-wrap;">{{ $gate['excerpt'] }}</pre>
                @endif
            @endforeach
        </div>
    @endif

    @if (! empty($report['changes']))
        <div style="{{ $section }}">
            <h3 style="{{ $heading }}">{{ __('admin.publications.changes_heading') }}</h3>
            @foreach ($report['changes'] as $change)
                <div style="{{ $row }}"><code>{{ $change['key'] }}</code><span style="{{ $muted }}">{{ __("admin.publications.changes.{$change['change']}") }}</span></div>
            @endforeach
        </div>
    @endif

    @if (! empty($report['tables']))
        <div style="{{ $section }}">
            <h3 style="{{ $heading }}">{{ __('admin.publications.tables') }}</h3>
            @foreach ($report['tables'] as $key => $state)
                <div style="{{ $row }}"><code>{{ $key }}</code><span style="{{ $muted }}">{{ __("admin.publications.table_states.{$state}") }}</span></div>
            @endforeach
        </div>
    @endif

    @if (! empty($report['warnings']))
        <div style="{{ $section }}">
            <h3 style="{{ $heading }}">{{ __('admin.publications.warnings') }}</h3>
            @foreach (array_slice($report['warnings'], 0, 20) as $issue)
                <div style="{{ $muted }}"><code>{{ trim(($issue['file'] ?? '').' '.($issue['pointer'] ?? '')) }}</code> {{ $issue['message'] }}</div>
            @endforeach
            @if (count($report['warnings']) > 20)
                <div style="{{ $muted }}">… {{ count($report['warnings']) - 20 }}</div>
            @endif
        </div>
    @endif

    @if (! empty($report['steps']))
        <div style="{{ $section }}">
            <h3 style="{{ $heading }}">{{ __('admin.publications.steps_heading') }}</h3>
            @foreach ($report['steps'] as $step)
                <div style="{{ $row }}"><span>{{ __("admin.publications.steps.{$step['key']}") }}</span><span style="{{ $muted }}">{{ round($step['ms'] / 1000, 1) }} s</span></div>
            @endforeach
        </div>
    @endif
</div>
