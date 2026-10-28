@if (count($changes) === 0)
    <p style="opacity: 0.8;">{{ __('admin.publications.no_changes') }}</p>
@else
    <ul style="display: grid; gap: 0.25rem; max-height: 16rem; overflow: auto; margin: 0; padding: 0; list-style: none;">
        @foreach ($changes as $change)
            <li style="display: flex; justify-content: space-between; gap: 1rem;">
                <code>{{ $change['key'] }}</code>
                <span style="opacity: 0.8;">{{ __("admin.publications.changes.{$change['change']}") }}</span>
            </li>
        @endforeach
    </ul>
@endif
