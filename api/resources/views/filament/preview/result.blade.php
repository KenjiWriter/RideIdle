@php
    $good = 'color: rgb(34, 197, 94);';
    $bad = 'color: rgb(239, 68, 68);';
    $cell = 'padding: 0.35rem 0.6rem; text-align: right; white-space: nowrap;';
    $head = $cell.' opacity: 0.7; font-weight: 500;';

    /** A change in percent, coloured by whether it is for the better (a lap is better when it is shorter). */
    $change = function (float|int|null $before, float|int|null $after, bool $lowerIsBetter = false) use ($good, $bad): string {
        if ($before === null || $after === null || $before == 0) {
            return '<span style="opacity: 0.6;">-</span>';
        }

        $percent = ($after - $before) / abs($before) * 100;

        if (abs($percent) < 0.05) {
            return '<span style="opacity: 0.6;">0%</span>';
        }

        $better = $lowerIsBetter ? $percent < 0 : $percent > 0;

        return '<span style="'.($better ? $good : $bad).'">'.($percent > 0 ? '+' : '').number_format($percent, 1).'%</span>';
    };
@endphp

@if ($result === null)
    <p style="opacity: 0.7;">{{ __('admin.preview.nothing_yet') }}</p>
@else
    <div style="display: grid; gap: 1.25rem; overflow-x: auto;">
        <p style="margin: 0;">
            <code>{{ $result['part'] }}</code> ({{ __('admin.preview.rarity') }} {{ $result['rarity'] }}) · <code>{{ $result['car'] }}</code>
            · {{ __("admin.preview.baseline.{$result['baseline']}", ['part' => $result['baseline_part'] ?? '']) }}
        </p>

        <div>
            <h3 style="font-weight: 600; margin: 0 0 0.35rem 0;">{{ __('admin.preview.stats') }}</h3>
            <table style="border-collapse: collapse;">
                <thead><tr><th style="{{ $head }} text-align: left;"></th><th style="{{ $head }}">{{ __('admin.preview.before') }}</th><th style="{{ $head }}">{{ __('admin.preview.after') }}</th><th style="{{ $head }}"></th></tr></thead>
                <tbody>
                    @foreach ($result['stats'] as $stat => $values)
                        <tr>
                            <td style="padding: 0.35rem 0.6rem;"><code>{{ $stat }}</code></td>
                            <td style="{{ $cell }}">{{ $values['before'] }}</td>
                            <td style="{{ $cell }}">{{ $values['after'] }}</td>
                            <td style="{{ $cell }}">{!! $change($values['before'], $values['after']) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>
            <h3 style="font-weight: 600; margin: 0 0 0.35rem 0;">{{ __('admin.preview.track') }}</h3>
            <table style="border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="{{ $head }} text-align: left;"></th>
                        <th style="{{ $head }}" colspan="3">{{ __('admin.preview.dry') }}</th>
                        <th style="{{ $head }}" colspan="3">{{ __('admin.preview.rain') }}</th>
                        <th style="{{ $head }}" colspan="2">{{ __('admin.preview.position') }}</th>
                        <th style="{{ $head }}" colspan="3">{{ __('admin.preview.income') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['tracks'] as $track => $row)
                        <tr style="border-top: 1px solid rgba(127, 127, 127, 0.25);">
                            <td style="padding: 0.35rem 0.6rem;">{{ $names[$track] ?? $track }}</td>
                            <td style="{{ $cell }}">{{ number_format($row['dry']['before'] / 1000, 2) }} s</td>
                            <td style="{{ $cell }}">{{ number_format($row['dry']['after'] / 1000, 2) }} s</td>
                            <td style="{{ $cell }}">{!! $change($row['dry']['before'], $row['dry']['after'], true) !!}</td>
                            @if ($row['rain']['after'] === null)
                                <td style="{{ $cell }} opacity: 0.6;" colspan="3">{{ __('admin.preview.no_rain') }}</td>
                            @else
                                <td style="{{ $cell }}">{{ number_format($row['rain']['before'] / 1000, 2) }} s</td>
                                <td style="{{ $cell }}">{{ number_format($row['rain']['after'] / 1000, 2) }} s</td>
                                <td style="{{ $cell }}">{!! $change($row['rain']['before'], $row['rain']['after'], true) !!}</td>
                            @endif
                            <td style="{{ $cell }}">{{ $row['position']['before'] ?? '-' }}</td>
                            <td style="{{ $cell }}">
                                @if ($row['position']['before'] !== null && $row['position']['after'] !== null && $row['position']['after'] != $row['position']['before'])
                                    <span style="{{ $row['position']['after'] < $row['position']['before'] ? $good : $bad }}">{{ $row['position']['after'] }}</span>
                                @else
                                    {{ $row['position']['after'] ?? '-' }}
                                @endif
                            </td>
                            <td style="{{ $cell }}">${{ number_format($row['income']['before'] / 100, 0) }}</td>
                            <td style="{{ $cell }}">${{ number_format($row['income']['after'] / 100, 0) }}</td>
                            <td style="{{ $cell }}">{!! $change($row['income']['before'], $row['income']['after']) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
