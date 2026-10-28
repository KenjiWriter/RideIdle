@php
    /** @var string|null $name the picture that the draft names */
@endphp

<div style="display: flex; align-items: center; gap: 12px;">
    @if ($name)
        <img
            src="{{ \App\Services\ContentImages::url($name) }}"
            alt=""
            loading="lazy"
            data-test="image-preview"
            style="max-width: 220px; max-height: 140px; border-radius: 8px; border: 1px solid rgba(128, 128, 128, .35); object-fit: cover;"
        />
        <code style="font-size: 12px; opacity: .6; word-break: break-all;">{{ $name }}</code>
    @else
        <p style="font-size: 14px; opacity: .7;" data-test="image-none">{{ __('admin.definitions.image_none') }}</p>
    @endif
</div>
