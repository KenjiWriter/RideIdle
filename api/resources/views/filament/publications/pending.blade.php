@php
    /**
     * @var list<array{definition: \App\Models\ContentDefinition, state: string, count: int, changes: list<array{type: string, group: string|null, label: string, from: string|null, to: string|null}>}> $entries
     * @var int $changes
     * @var string|null $pack
     * @var string $publicationsUrl
     * @var array<int, string|null> $editUrls
     */
    $box = 'border: 1px solid rgba(128, 128, 128, .3); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 10px;';
    $muted = 'font-size: 13px; opacity: .7;';
    $cell = 'padding: 6px 10px; text-align: left; border-bottom: 1px solid rgba(128, 128, 128, .2); font-size: .875rem; vertical-align: top;';
    $stateColor = ['new' => 'info', 'changed' => 'warning', 'same' => 'gray'];
    $typeMark = ['added' => '+', 'removed' => '−', 'changed' => '~'];
@endphp

<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; gap: 16px;" data-test="pending-changes">
        <div style="{{ $box }}">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <strong data-test="pending-summary">{{ __('admin.pending.summary', ['definitions' => count($entries), 'changes' => $changes]) }}</strong>
                <x-filament::button tag="a" :href="$publicationsUrl" size="sm" color="gray" data-test="pending-publish">{{ __('admin.pending.publish') }}</x-filament::button>
            </div>
            <div style="{{ $muted }}">{{ __('admin.pending.intro') }}</div>
            <div style="{{ $muted }}" data-test="pending-pack">{{ $pack === null ? __('admin.pending.no_pack') : __('admin.pending.live_pack', ['number' => $pack]) }}</div>
        </div>

        @forelse ($entries as $entry)
            @php($definition = $entry['definition'])
            <details style="{{ $box }}" data-test="pending-definition" data-slug="{{ $definition->slug }}" data-state="{{ $entry['state'] }}" @if ($entry['state'] === 'changed' && $entry['count'] <= 12) open @endif>
                <summary style="cursor: pointer; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <strong>{{ $definition->kind === 'rules' ? __('admin.pending.rules').': '.$definition->slug : $definition->title() }}</strong>
                    <x-filament::badge size="sm" color="gray">{{ __('admin.pending.kind.'.$definition->kind) }}</x-filament::badge>
                    <x-filament::badge size="sm" :color="$stateColor[$entry['state']]">{{ __('admin.pending.state.'.$entry['state']) }}</x-filament::badge>
                    @if ($entry['state'] === 'changed')
                        <span style="{{ $muted }}">{{ __('admin.pending.count', ['count' => $entry['count']]) }}</span>
                    @endif
                    @if ($definition->draft_saved_at)
                        <span style="{{ $muted }}">{{ __('admin.definitions.draft_since', ['when' => $definition->draft_saved_at->format('Y-m-d H:i')]) }}</span>
                    @endif
                </summary>

                @if ($entry['state'] === 'new')
                    <p style="margin: 0; {{ $muted }}">{{ __('admin.pending.new_hint') }}</p>
                @elseif ($entry['state'] === 'same')
                    <p style="margin: 0; {{ $muted }}">{{ __('admin.pending.same_hint') }}</p>
                @else
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;" data-test="pending-table">
                            <thead>
                                <tr>
                                    <th style="{{ $cell }} font-weight: 600;">{{ __('admin.pending.where') }}</th>
                                    <th style="{{ $cell }} font-weight: 600;">{{ __('admin.pending.was') }}</th>
                                    <th style="{{ $cell }} font-weight: 600;">{{ __('admin.pending.is') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entry['changes'] as $change)
                                    <tr data-test="pending-change" data-type="{{ $change['type'] }}">
                                        <td style="{{ $cell }}">
                                            <span style="opacity: .6;">{{ $typeMark[$change['type']] }}</span>
                                            @if ($change['group'])
                                                <span style="{{ $muted }}">{{ $change['group'] }} ›</span>
                                            @endif
                                            <span @if (! $change['group']) style="font-family: ui-monospace, monospace; font-size: .8125rem;" @endif>{{ $change['label'] }}</span>
                                        </td>
                                        <td style="{{ $cell }}">{{ $change['from'] ?? '—' }}</td>
                                        <td style="{{ $cell }}"><strong>{{ $change['to'] ?? '—' }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($entry['count'] > count($entry['changes']))
                        <p style="margin: 0; {{ $muted }}">{{ __('admin.pending.more', ['count' => $entry['count'] - count($entry['changes'])]) }}</p>
                    @endif
                @endif

                @if ($editUrls[$definition->id] ?? null)
                    <div><x-filament::button tag="a" :href="$editUrls[$definition->id]" size="xs" color="gray">{{ __('admin.pending.open') }}</x-filament::button></div>
                @endif
            </details>
        @empty
            <div style="{{ $box }}" data-test="pending-none">
                <strong>{{ __('admin.pending.none') }}</strong>
                <span style="{{ $muted }}">{{ __('admin.pending.none_hint') }}</span>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
