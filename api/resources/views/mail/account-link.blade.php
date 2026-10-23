<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<body style="margin:0;padding:24px;background:#0e1013;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#eceef2">
<div style="max-width:480px;margin:0 auto;background:#151920;border:1px solid #262c36;border-radius:12px;padding:24px">
    <p style="margin:0 0 16px;font-size:20px;font-weight:700;color:#ffb224">RideIdle</p>
    <p style="margin:0 0 20px;font-size:16px;line-height:1.5">{{ __("mail.account_link.{$kind}.intro", ['driver' => $driver]) }}</p>
    <p style="margin:0 0 24px">
        <a href="{{ $url }}" style="display:inline-block;background:#ffb224;color:#2a1800;font-weight:700;text-decoration:none;padding:14px 24px;border-radius:8px">{{ __("mail.account_link.{$kind}.action") }}</a>
    </p>
    <p style="margin:0 0 8px;font-size:14px;color:#939bab">{{ __('mail.account_link.expires', ['minutes' => $minutes]) }}</p>
    <p style="margin:0 0 16px;font-size:14px;color:#939bab">{{ __('mail.account_link.ignore') }}</p>
    <p style="margin:0 0 4px;font-size:12px;color:#939bab">{{ __('mail.account_link.fallback') }}</p>
    <p style="margin:0;font-size:12px;color:#939bab;word-break:break-all">{{ $url }}</p>
</div>
</body>
</html>
