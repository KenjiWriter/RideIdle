@php
    /**
     * @var list<array{slug: string, name: string, domain: string|null, live: bool, stats: array<string, mixed>, revenue: string}> $tiles
     * @var bool $canCreate
     * @var string $createUrl
     */
    $number = fn (int $value): string => number_format($value, 0, ',', ' ');
    $label = 'font-size: 12px; opacity: .6;';
    $value = 'font-size: 20px; font-weight: 600; line-height: 1.3;';
@endphp

<x-filament-widgets::widget>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;" data-test="game-tiles">
        @foreach ($tiles as $tile)
            <x-filament::section :compact="true" data-test="game-tile-{{ $tile['slug'] }}">
                <x-slot name="heading">{{ $tile['name'] }}</x-slot>
                <x-slot name="description">
                    @if ($tile['domain'])
                        <a href="https://{{ $tile['domain'] }}" target="_blank" rel="noopener" style="text-decoration: underline;">{{ $tile['domain'] }}</a>
                    @else
                        {{ __('admin.network.tile.no_domain') }}
                    @endif
                </x-slot>
                <x-slot name="afterHeader">
                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                        <x-filament::badge :color="$tile['live'] ? 'success' : 'warning'" data-test="game-status">
                            {{ __('admin.games.status_'.($tile['live'] ? 'live' : 'draft')) }}
                        </x-filament::badge>
                        @if ($tile['domain'])
                            @php($ssl = $tile['stats']['ssl'])
                            <x-filament::badge :color="['none' => 'gray', 'pending' => 'info', 'ok' => 'success', 'soon' => 'warning', 'expired' => 'danger', 'failed' => 'danger'][$ssl['state']]" data-test="tile-ssl" data-state="{{ $ssl['state'] }}">
                                {{ __('admin.network.tile.ssl.'.($ssl['state'] === 'ok' && $ssl['expires'] === null ? 'ok_unknown' : $ssl['state']), ['date' => $ssl['expires']]) }}
                            </x-filament::badge>
                        @endif
                    </div>
                </x-slot>

                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                    <div>
                        <div style="{{ $label }}">{{ __('admin.network.tile.accounts') }}</div>
                        <div style="{{ $value }}" data-test="tile-accounts">{{ $number($tile['stats']['accounts']) }}</div>
                    </div>
                    <div>
                        <div style="{{ $label }}">{{ __('admin.network.tile.active') }}</div>
                        <div style="{{ $value }}" data-test="tile-active">{{ $number($tile['stats']['active_week']) }}</div>
                    </div>
                    <div>
                        <div style="{{ $label }}">{{ __('admin.network.tile.new') }}</div>
                        <div style="{{ $value }}" data-test="tile-new">{{ $number($tile['stats']['new_week']) }}</div>
                    </div>
                    <div>
                        <div style="{{ $label }}">{{ __('admin.network.tile.revenue') }}</div>
                        <div style="font-size: 15px; font-weight: 600; line-height: 1.6;" data-test="tile-revenue">{{ $tile['revenue'] }}</div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 16px;">
                    <span style="font-size: 12px; opacity: .6;" data-test="tile-pack">
                        @if ($tile['stats']['pack'] !== null)
                            {{ __('admin.network.tile.pack', ['number' => $tile['stats']['pack']]) }}
                        @elseif ($tile['stats']['from_files'])
                            {{ __('admin.network.tile.from_files') }}
                        @else
                            {{ __('admin.network.tile.no_pack') }}
                        @endif
                    </span>
                    <x-filament::button size="sm" wire:click="enter('{{ $tile['slug'] }}')" data-test="enter-{{ $tile['slug'] }}">
                        {{ __('admin.network.tile.enter') }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endforeach

        @if ($canCreate)
            <a href="{{ $createUrl }}" data-test="game-tile-create" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; min-height: 180px; border: 2px dashed rgba(128, 128, 128, .4); border-radius: 12px; padding: 16px; text-align: center; text-decoration: none; color: inherit;">
                <span style="font-size: 28px; line-height: 1;">+</span>
                <span style="font-weight: 600;">{{ __('admin.network.tile.create') }}</span>
                <span style="font-size: 12px; opacity: .6;">{{ __('admin.network.tile.create_hint') }}</span>
            </a>
        @endif
    </div>
</x-filament-widgets::widget>
