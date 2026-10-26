<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#171A2E">
    <h2 style="margin:0 0 12px">{{ __($pickup ? 'site.shop.mail_ready_subject' : 'site.shop.mail_sent_subject', $details) }}</h2>
    <p style="margin:0 0 12px">{{ __($pickup ? 'site.shop.msg_ready' : 'site.shop.msg_sent', $details) }}</p>
    @if (! $pickup && $details['tracking'])
        <p style="margin:0 0 12px">{{ __('site.shop.tracking_number') }}: <b style="font-size:18px;letter-spacing:.04em">{{ $details['tracking'] }}</b></p>
        @if ($courier['track_url'] ?? null)
            <p style="margin:0 0 12px"><a href="{{ $courier['track_url'] }}">{{ __('site.shop.track_on', ['courier' => $courier['name']]) }}</a><br>
            <span style="color:#4C5170;font-size:13px">{{ __('site.shop.track_hint', ['courier' => $courier['name']]) }}</span></p>
        @endif
    @endif
    @if ($order->get('delivery_message'))
        <p style="margin:0 0 12px;padding:10px 12px;background:#ECEEF5;border-radius:8px">{{ $order->get('delivery_message') }}</p>
    @endif
    <p style="margin:0"><a href="{{ $details['url'] }}">{{ __('site.shop.mail_view') }}</a></p>
</div>
