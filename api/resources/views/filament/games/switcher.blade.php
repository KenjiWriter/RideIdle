@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Game> $games */
@endphp

<div data-test="game-switcher" style="display: flex; align-items: center; margin-inline-end: 0.75rem;">
    @if ($games->count() > 1)
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="slug" aria-label="{{ __('admin.games.switch') }}" data-test="game-select">
                @foreach ($games as $game)
                    <option value="{{ $game->slug }}">{{ $game->name }}{{ $game->isLive() ? '' : ' ('.__('admin.games.status_draft').')' }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    @elseif ($games->count() === 1)
        <span data-test="game-name" style="font-weight: 600;">{{ $games->first()->name }}</span>
    @endif
</div>
