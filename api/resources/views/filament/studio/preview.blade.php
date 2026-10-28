@php
    /** @var array{errors: list<array{file: string, pointer: string, message: string}>, gates: list<array{name: string, passed: bool, ms: int, excerpt: string}>, now: array<string, mixed>, live: array<string, mixed>|null, changes: int} $report */
    $cell = 'padding: 4px 10px; text-align: right; border-bottom: 1px solid rgba(128,128,128,.25);';
    $head = 'padding: 4px 10px; text-align: right; border-bottom: 2px solid rgba(128,128,128,.5); font-weight: 600;';
    $livePace = collect($report['live']['pace'] ?? [])->keyBy(fn (array $row): string => $row['player'].'|'.$row['car']);
    $liveLadder = collect($report['live']['ladder'] ?? [])->keyBy('level');
    $percent = fn (float $deviation): string => ($deviation > 0 ? '+' : '').number_format($deviation * 100, 1, ',', ' ').'%';
@endphp

<div style="display: flex; flex-direction: column; gap: 18px; font-size: 14px;">
    @if ($report['errors'] !== [])
        <div>
            <p style="font-weight: 600; color: #ef4444;">{{ __('admin.studio.errors') }}</p>
            <ul style="margin-top: 6px; padding-left: 18px; list-style: disc;">
                @foreach ($report['errors'] as $issue)
                    <li>{{ $issue['pointer'] === '' ? '' : $issue['pointer'].': ' }}{{ $issue['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <p>{{ __('admin.studio.changes', ['count' => $report['changes']]) }}</p>

        <div>
            <p style="font-weight: 600;">{{ __('admin.studio.gates') }}</p>
            <ul style="margin-top: 6px; padding-left: 18px; list-style: disc;">
                @foreach ($report['gates'] as $gate)
                    <li>
                        <code>{{ $gate['name'] }}</code>:
                        <strong style="color: {{ $gate['passed'] ? '#22c55e' : '#ef4444' }};">{{ $gate['passed'] ? __('admin.studio.passed') : __('admin.studio.failed') }}</strong>
                        <span style="opacity: .6;">({{ number_format($gate['ms'] / 1000, 1, ',', ' ') }} s)</span>
                        @if (! $gate['passed'])
                            <pre style="margin-top: 4px; max-height: 200px; overflow: auto; font-size: 12px; white-space: pre-wrap;">{{ $gate['excerpt'] }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($report['live'] === null)
            <p style="opacity: .7;">{{ __('admin.studio.no_live') }}</p>
        @endif

        <div>
            <p style="font-weight: 600;">{{ __('admin.studio.pace') }}</p>
            <div style="overflow-x: auto; margin-top: 6px;">
                <table style="border-collapse: collapse; min-width: 100%;">
                    <thead>
                        <tr>
                            <th style="{{ $head }} text-align: left;">{{ __('admin.studio.player') }}</th>
                            <th style="{{ $head }} text-align: left;">{{ __('admin.studio.rung') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.target') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.live_column') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.draft_column') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.deviation') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['now']['pace'] ?? [] as $row)
                            @php($before = $livePace->get($row['player'].'|'.$row['car']))
                            <tr>
                                <td style="{{ $cell }} text-align: left;">{{ $row['player'] }}</td>
                                <td style="{{ $cell }} text-align: left;">{{ $row['car'] }}</td>
                                <td style="{{ $cell }}">{{ $row['target_day'] }}</td>
                                <td style="{{ $cell }}">{{ $before['day'] ?? '-' }}</td>
                                <td style="{{ $cell }} font-weight: 600; {{ $before !== null && $before['day'] !== $row['day'] ? 'color: #f59e0b;' : '' }}">{{ $row['day'] }}</td>
                                <td style="{{ $cell }} color: {{ $row['passed'] ? 'inherit' : '#ef4444' }};">{{ $percent($row['deviation']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <p style="font-weight: 600;">{{ __('admin.studio.ladder') }}</p>
            <div style="overflow-x: auto; margin-top: 6px;">
                <table style="border-collapse: collapse; min-width: 100%;">
                    <thead>
                        <tr>
                            <th style="{{ $head }} text-align: left;">{{ __('admin.studio.level') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.anchor') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.live_column') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.draft_column') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.deviation') }}</th>
                            <th style="{{ $head }}">{{ __('admin.studio.xp_hour') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['now']['ladder'] ?? [] as $row)
                            @php($before = $liveLadder->get($row['level']))
                            <tr>
                                <td style="{{ $cell }} text-align: left;">{{ $row['level'] }}</td>
                                <td style="{{ $cell }}">${{ number_format($row['anchor'], 0, ',', ' ') }}</td>
                                <td style="{{ $cell }}">{{ $before === null ? '-' : '$'.number_format($before['game'], 0, ',', ' ') }}</td>
                                <td style="{{ $cell }} font-weight: 600; {{ $before !== null && $before['game'] != $row['game'] ? 'color: #f59e0b;' : '' }}">${{ number_format($row['game'], 0, ',', ' ') }}</td>
                                <td style="{{ $cell }} color: {{ $row['ok'] ? 'inherit' : '#ef4444' }};">{{ $percent($row['deviation']) }}</td>
                                <td style="{{ $cell }}">{{ $row['xp_game'] === null ? '-' : number_format($row['xp_game'], 0, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
