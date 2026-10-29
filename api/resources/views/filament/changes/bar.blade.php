{{--
    What has been changed in the form and not saved yet, drawn in the browser from the form as it is typed. $baseline holds the values as they were saved, $fields the
    fields that can be told (label, kind, options, and a line about what changing it does).
--}}
<div
    wire:key="changes-bar-{{ $version }}"
    x-data="{
        baseline: @js($baseline),
        fields: @js($fields),
        yes: @js(__('admin.changes.yes')),
        no: @js(__('admin.changes.no')),
        empty: @js(__('admin.changes.empty')),
        open: true,
        current(path) {
            const value = $wire.$get('data.' + path)
            return value === null || value === undefined ? '' : value
        },
        saved(path) {
            const value = this.baseline[path]
            return value === null || value === undefined ? '' : value
        },
        same(path, a, b) {
            if (this.fields[path].kind === 'bool') {
                return this.truthy(a) === this.truthy(b)
            }
            if (a !== '' && b !== '' && !isNaN(Number(a)) && !isNaN(Number(b))) {
                return Number(a) === Number(b)
            }
            return String(a) === String(b)
        },
        truthy(value) {
            return value === true || value === 1 || value === '1' || value === 'true'
        },
        show(path, value) {
            const field = this.fields[path]
            if (field.kind === 'bool') {
                return this.truthy(value) ? this.yes : this.no
            }
            if (value === '') {
                return this.empty
            }
            if (field.options && field.options[value] !== undefined) {
                return field.options[value]
            }
            return String(value)
        },
        get rows() {
            return Object.keys(this.fields)
                .filter((path) => {
                    const now = this.current(path)

                    return typeof now !== 'object' && !this.same(path, now, this.saved(path))
                })
                .map((path) => ({
                    path,
                    label: this.fields[path].label,
                    effect: this.fields[path].effect,
                    from: this.show(path, this.saved(path)),
                    to: this.show(path, this.current(path)),
                }))
        },
        revert(path) {
            $wire.$set('data.' + path, this.fields[path].kind === 'bool' ? this.truthy(this.baseline[path]) : (this.baseline[path] ?? null), false)
        },
        revertAll() {
            this.rows.forEach((row) => this.revert(row.path))
        },
    }"
    x-show="rows.length > 0"
    x-cloak
    data-test="changes-bar"
    style="position: sticky; top: 4.25rem; z-index: 5; margin-bottom: 16px; border: 1px solid rgba(245, 158, 11, .55); background: rgba(245, 158, 11, .1); border-radius: 12px; padding: 12px 16px; backdrop-filter: blur(6px);"
>
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <button type="button" x-on:click="open = ! open" style="font-weight: 600; background: none; border: 0; padding: 0; cursor: pointer; color: inherit; text-align: left;">
            <span x-text="@js(__('admin.changes.title')) + ' (' + rows.length + ')'"></span>
            <span style="opacity: .6; font-weight: 400;" x-text="open ? '▲' : '▼'"></span>
        </button>
        <button type="button" x-on:click="revertAll()" style="background: none; border: 0; padding: 0; cursor: pointer; color: inherit; text-decoration: underline; font-size: .875rem;" data-test="changes-revert-all">
            {{ __('admin.changes.revert_all') }}
        </button>
    </div>

    <ul x-show="open" style="list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 8px;">
        <template x-for="row in rows" :key="row.path">
            <li style="display: grid; gap: 2px; font-size: .875rem;" data-test="change-row">
                <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <span>
                        <strong x-text="row.label"></strong>:
                        <span style="opacity: .65; text-decoration: line-through;" x-text="row.from"></span>
                        →
                        <strong x-text="row.to"></strong>
                    </span>
                    <button type="button" x-on:click="revert(row.path)" style="background: none; border: 0; padding: 0; cursor: pointer; color: inherit; text-decoration: underline; font-size: .8125rem;">
                        {{ __('admin.changes.revert') }}
                    </button>
                </div>
                <div x-show="row.effect" style="opacity: .75; font-size: .8125rem;" x-text="row.effect"></div>
            </li>
        </template>
    </ul>
</div>
