<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#171A2E">
    <h2 style="margin:0 0 12px">{{ __('site.shop.mail_rejected_subject', $details) }}</h2>
    <p style="margin:0 0 12px">{{ __('site.shop.msg_rejected', $details) }}</p>
    @if ($details['message'] !== '')
        <p style="margin:0 0 12px;padding:10px 12px;background:#ECEEF5;border-radius:8px;white-space:pre-line">{{ __('site.shop.msg_rejected_reason', $details) }}</p>
    @endif
    <p style="margin:0 0 12px">{{ __('site.shop.payment_try_again') }}</p>
    <p style="margin:0"><a href="{{ $details['url'] }}">{{ __('site.shop.mail_view') }}</a></p>
</div>
