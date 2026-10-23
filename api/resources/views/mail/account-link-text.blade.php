{{ __("mail.account_link.{$kind}.intro", ['driver' => $driver]) }}

{{ __("mail.account_link.{$kind}.action") }}: {{ $url }}

{{ __('mail.account_link.expires', ['minutes' => $minutes]) }}
{{ __('mail.account_link.ignore') }}
