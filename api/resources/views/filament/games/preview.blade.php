{{--
    How a game looks to a player as the form is typed: its tab in the browser, its address, and the bar of its app. It reads the form in the browser, so it changes with every key.
    $installation is the host of the installation, which a game with no domain of its own answers on.
--}}
<div
    x-data="{
        installation: @js($installation),
        fallback: @js($fallback),
        get name() {
            return (String($wire.$get('data.name') ?? '')).trim() || this.fallback
        },
        get color() {
            const value = String($wire.$get('data.theme_color') ?? '')

            return /^#[0-9a-fA-F]{6}$/.test(value) ? value : '#0e1013'
        },
        get domain() {
            return (String($wire.$get('data.domain') ?? '')).trim().toLowerCase()
        },
        get live() {
            return $wire.$get('data.status') === 'live'
        },
        get letters() {
            return this.name.split(/\s+/).map((word) => Array.from(word)[0] ?? '').join('').slice(0, 2).toUpperCase()
        },
        get ink() {
            const r = parseInt(this.color.slice(1, 3), 16)
            const g = parseInt(this.color.slice(3, 5), 16)
            const b = parseInt(this.color.slice(5, 7), 16)

            return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#111111' : '#ffffff'
        },
    }"
    data-test="game-preview"
    style="margin-bottom: 16px; border: 1px solid rgba(128, 128, 128, .3); border-radius: 12px; padding: 12px 16px; display: grid; gap: 10px;"
>
    <div style="font-weight: 600;">{{ __('admin.changes.preview.heading') }}</div>

    <div style="display: grid; gap: 4px;">
        <span style="font-size: .75rem; opacity: .65;">{{ __('admin.changes.preview.tab') }}</span>
        <div style="display: inline-flex; align-items: center; gap: 8px; max-width: 280px; padding: 6px 12px; border-radius: 10px 10px 0 0; border: 1px solid rgba(128, 128, 128, .35); border-bottom: 0; font-size: .8125rem;">
            <span x-text="letters" x-bind:style="'display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:4px;font-size:.625rem;font-weight:700;background:' + color + ';color:' + ink"></span>
            <span x-text="name" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" data-test="preview-name"></span>
        </div>
    </div>

    <div style="display: grid; gap: 4px;">
        <span style="font-size: .75rem; opacity: .65;">{{ __('admin.changes.preview.address') }}</span>
        <div style="font-size: .875rem; padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(128, 128, 128, .35);" data-test="preview-address">
            <span x-show="domain" x-text="'https://' + domain"></span>
            <span x-show="! domain" style="opacity: .75;">{{ __('admin.changes.preview.installation') }}: <span x-text="installation"></span></span>
        </div>
    </div>

    <div style="display: grid; gap: 4px;">
        <span style="font-size: .75rem; opacity: .65;">{{ __('admin.changes.preview.bar') }}</span>
        <div x-bind:style="'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-radius:10px;background:' + color + ';color:' + ink" data-test="preview-bar">
            <strong x-text="name"></strong>
            <span x-text="live ? @js(__('admin.games.status_live')) : @js(__('admin.games.status_draft'))" style="font-size: .75rem; padding: 2px 8px; border-radius: 999px; border: 1px solid currentColor;"></span>
        </div>
    </div>

    <p x-show="! live" style="margin: 0; font-size: .8125rem; opacity: .75;">{{ __('admin.changes.preview.draft') }}</p>
</div>
