@php
    $cell = 'padding: 6px 10px; text-align: left; border-bottom: 1px solid rgba(128, 128, 128, .2); font-size: .875rem;';
    $head = $cell.' font-weight: 600; white-space: nowrap;';
    $money = $cell.' text-align: right; white-space: nowrap;';
@endphp

<div style="display: grid; gap: 16px;" data-test="player-history">
    <x-filament::section :heading="__('admin.players.section.cars')">
        @if ($cars === [])
            <p style="margin: 0; opacity: .7;" data-test="no-cars">{{ __('admin.players.history.none') }}</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;" data-test="player-cars">
                    <thead>
                        <tr>
                            <th style="{{ $head }}">{{ __('admin.players.history.vehicle') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.level') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.mileage') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.condition') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cars as $car)
                            <tr>
                                <td style="{{ $cell }}">{{ $car['name'] }}@if ($car['selected']) <x-filament::badge size="sm" style="display: inline-flex;">{{ __('admin.players.history.selected') }}</x-filament::badge>@endif</td>
                                <td style="{{ $cell }}">{{ $car['level'] }}</td>
                                <td style="{{ $cell }}">{{ $car['km'] }} km</td>
                                <td style="{{ $cell }}">{{ $car['condition'] }}%</td>
                                <td style="{{ $cell }}">{{ $car['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.players.section.purchases')">
        @if ($purchases === [])
            <p style="margin: 0; opacity: .7;" data-test="no-purchases">{{ __('admin.players.history.none') }}</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;" data-test="player-purchases">
                    <thead>
                        <tr>
                            <th style="{{ $head }}">{{ __('admin.players.history.when') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.product') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.coins') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.amount') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($purchases as $purchase)
                            <tr>
                                <td style="{{ $cell }}">{{ $purchase['when'] }}</td>
                                <td style="{{ $cell }}">{{ $purchase['product'] }}</td>
                                <td style="{{ $cell }}">{{ $purchase['coins'] }}</td>
                                <td style="{{ $money }}">{{ $purchase['amount'] }}</td>
                                <td style="{{ $cell }}">{{ $purchase['status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.players.section.ledger', ['count' => count($ledger)])">
        @if ($ledger === [])
            <p style="margin: 0; opacity: .7;" data-test="no-ledger">{{ __('admin.players.history.none') }}</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;" data-test="player-ledger">
                    <thead>
                        <tr>
                            <th style="{{ $head }}">{{ __('admin.players.history.when') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.currency') }}</th>
                            <th style="{{ $head }}">{{ __('admin.players.history.reason') }}</th>
                            <th style="{{ $head }}; text-align: right;">{{ __('admin.players.history.change') }}</th>
                            <th style="{{ $head }}; text-align: right;">{{ __('admin.players.history.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledger as $entry)
                            <tr>
                                <td style="{{ $cell }}">{{ $entry['when'] }}</td>
                                <td style="{{ $cell }}">{{ $entry['currency'] }}</td>
                                <td style="{{ $cell }}">{{ $entry['reason'] }}</td>
                                <td style="{{ $money }}">{{ $entry['change'] }}</td>
                                <td style="{{ $money }}">{{ $entry['balance'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
