<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#171A2E">
    <h2 style="margin:0 0 12px">{{ __('site.payouts.mail_subject', $details) }}</h2>
    <p style="margin:0 0 12px">{{ __('site.payouts.mail_body', $details) }}</p>
    <p style="margin:0 0 12px">
        @if ($details['number'] ?? ''){{ __('site.payouts.mail_number', $details) }}<br>@endif
        @if ($details['date']){{ __('site.payouts.paid_on', ['date' => $details['date']]) }}<br>@endif
        @if ($details['ref']){{ __('site.payouts.mail_ref', $details) }}@endif
    </p>
    @if ($details['note'] !== '')
        <p style="margin:0 0 12px;padding:10px 12px;background:#ECEEF5;border-radius:8px;white-space:pre-line">{{ __('site.payouts.from_team', ['note' => $details['note']]) }}</p>
    @endif
    @if ($details['breakdown'] !== '')
        <p style="margin:0 0 4px;font-weight:600">{{ __('site.payouts.mail_items') }}</p>
        <p style="margin:0 0 12px;color:#4C5170;font-size:14px;white-space:pre-line">{{ $details['breakdown'] }}</p>
    @endif
    <p style="margin:0"><a href="{{ $details['url'] }}">{{ __('site.payouts.mail_link') }}</a></p>
</div>
