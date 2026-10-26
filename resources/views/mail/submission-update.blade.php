@php($key = fn ($name) => "site.{$group}.{$name}")
<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#171A2E">
    <h2 style="margin:0 0 12px">{{ __($key($live ? 'mail_live_subject' : 'mail_changes_subject'), ['product' => $title, 'title' => $title]) }}</h2>
    <p style="margin:0 0 12px">{{ __($key($live ? 'mail_live_body' : 'mail_changes_body')) }}</p>
    @if ($note)
        <p style="margin:0 0 12px;padding:10px 12px;background:#ECEEF5;border-radius:8px;white-space:pre-line">{{ $note }}</p>
    @endif
    <p style="margin:0"><a href="{{ $url }}">{{ __($key($live ? 'mail_live_link' : 'mail_changes_link')) }}</a></p>
</div>
