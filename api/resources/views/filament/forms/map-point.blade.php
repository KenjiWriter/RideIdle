@php
    use App\Filament\Forms\Components\MapPoint;

    $context = $getContext();
    $cities = $context['cities'] ?? [];
    $roads = $context['roads'] ?? [];
    $own = $context['own'] ?? null;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle('{{ $getStatePath() }}'),
            dragging: false,
            init() {
                if (! this.state || this.state.x === undefined || this.state.x === null) {
                    this.state = { x: {{ MapPoint::WIDTH / 2 }}, y: {{ MapPoint::TOP + MapPoint::HEIGHT / 2 }} }
                }
            },
            put(event) {
                const board = this.$refs.board
                const point = board.createSVGPoint()
                point.x = event.clientX
                point.y = event.clientY
                const at = point.matrixTransform(board.getScreenCTM().inverse())
                this.state = {
                    x: Math.max(0, Math.min({{ MapPoint::WIDTH }}, Math.round(at.x))),
                    y: Math.max(0, Math.min({{ MapPoint::MAX_Y }}, Math.round(at.y))),
                }
            },
        }"
        x-on:pointerup.window="dragging = false"
        style="display: grid; gap: 0.5rem;"
    >
        <svg
            x-ref="board"
            viewBox="0 {{ MapPoint::TOP }} {{ MapPoint::WIDTH }} {{ MapPoint::HEIGHT }}"
            x-on:pointerdown.prevent="dragging = true; put($event)"
            x-on:pointermove="dragging && put($event)"
            style="width: 100%; max-width: 32rem; aspect-ratio: {{ MapPoint::WIDTH }} / {{ MapPoint::HEIGHT }}; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); cursor: crosshair; touch-action: none;"
        >
            @foreach ($roads as $road)
                @php($other = $own === null ? null : ($road['from'] === $own ? $road['to'] : ($road['to'] === $own ? $road['from'] : null)))
                @if ($other !== null && isset($cities[$other]))
                    {{-- A road of the city that is being placed leaves from its point, wherever that is dragged to. --}}
                    <line
                        x-bind:x1="state?.x"
                        x-bind:y1="state?.y"
                        x2="{{ $cities[$other]['x'] }}"
                        y2="{{ $cities[$other]['y'] }}"
                        stroke="rgba(127, 127, 127, 0.7)"
                        stroke-width="2"
                        stroke-dasharray="6 4"
                    />
                @elseif (isset($cities[$road['from']], $cities[$road['to']]))
                    <line
                        x1="{{ $cities[$road['from']]['x'] }}"
                        y1="{{ $cities[$road['from']]['y'] }}"
                        x2="{{ $cities[$road['to']]['x'] }}"
                        y2="{{ $cities[$road['to']]['y'] }}"
                        stroke="rgba(127, 127, 127, 0.7)"
                        stroke-width="2"
                        stroke-dasharray="6 4"
                    />
                @endif
            @endforeach
            @foreach ($cities as $city)
                <circle cx="{{ $city['x'] }}" cy="{{ $city['y'] }}" r="5" fill="rgba(127, 127, 127, 0.8)" />
                <text x="{{ $city['x'] + 8 }}" y="{{ $city['y'] + 4 }}" font-size="11" fill="currentColor" opacity="0.8">{{ $city['name'] }}</text>
            @endforeach
            <circle x-show="state" x-bind:cx="state?.x" x-bind:cy="state?.y" r="9" style="fill: var(--primary-500);" stroke="white" stroke-width="2" />
        </svg>

        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <label style="display: flex; gap: 0.35rem; align-items: center;">
                <span>x</span>
                <input type="number" min="0" max="{{ MapPoint::WIDTH }}" x-model.number="state.x" style="width: 6rem; padding: 0.25rem 0.5rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.4); background: transparent;" />
            </label>
            <label style="display: flex; gap: 0.35rem; align-items: center;">
                <span>y</span>
                <input type="number" min="0" max="{{ MapPoint::MAX_Y }}" x-model.number="state.y" style="width: 6rem; padding: 0.25rem 0.5rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.4); background: transparent;" />
            </label>
        </div>
    </div>
</x-dynamic-component>
