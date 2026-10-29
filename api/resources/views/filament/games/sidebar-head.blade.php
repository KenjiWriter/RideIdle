@php
    $network = \App\Filament\Support\AdminLevel::isNetwork();
    $game = \App\Models\Game::query()->find(app(\App\Games\CurrentGame::class)->id());
    $admin = \Illuminate\Support\Facades\Auth::guard('admin')->user();
    $count = $admin instanceof \App\Models\Admin ? $admin->allowedGames()->count() : 0;
@endphp

{{-- Where the admin is: the network of games, or the one game that the menu below is about. --}}
<div data-test="sidebar-head" style="margin: 0 0 12px; padding: 10px 12px; border: 1px solid rgba(128, 128, 128, .3); border-radius: 10px; font-size: 13px;">
    @if ($network)
        <div style="font-weight: 600;" data-test="sidebar-network">{{ __('admin.network.sidebar.network') }}</div>
        <div style="opacity: .6;">{{ __('admin.network.sidebar.games', ['count' => $count]) }}</div>
    @elseif ($game)
        <div style="opacity: .6; font-size: 11px; text-transform: uppercase; letter-spacing: .06em;">{{ __('admin.network.sidebar.game') }}</div>
        <div style="font-weight: 600;" data-test="sidebar-game">{{ $game->name }}</div>
        <div style="opacity: .6; overflow: hidden; text-overflow: ellipsis;">{{ $game->domain ?? __('admin.network.tile.no_domain') }}</div>
    @endif
</div>
