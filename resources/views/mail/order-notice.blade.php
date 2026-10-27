@php($o = $order)
<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#171A2E">
    <h2 style="margin:0 0 12px">{{ $subjectLine }}</h2>
    <p style="margin:0 0 12px">
        <b>{{ $o->get('customer_name') }}</b> · {{ $o->get('customer_phone') }}@if ($o->get('customer_email')) · {{ $o->get('customer_email') }}@endif<br>
        {{ $o->get('delivery') === 'delivery' ? 'Delivery to: '.$o->get('address') : 'Pick up' }}<br>
        Payment: {{ ['onepay' => 'BCEL One (OnePay QR)', 'transfer' => 'bank transfer / QR', 'cash' => 'cash'][$o->get('payment_method')] ?? $o->get('payment_method') }} — {{ $o->get('payment_status') }}
        @if ($o->get('payment_note'))<br>{{ $o->get('payment_method') === 'onepay' ? 'Note' : "Customer's payment message" }}: {{ $o->get('payment_note') }}@endif
    </p>
    @if ($o->get('status') === 'cancelled' && $o->get('cancel_note'))
        <p style="margin:0 0 12px;padding:10px 12px;background:#FBE9EC;border-radius:8px;white-space:pre-line">{{ $o->get('cancel_note') }}</p>
    @endif
    @if ($o->get('onepay_summary'))
        <p style="margin:0 0 12px;padding:10px 12px;background:#ECEEF5;border-radius:8px;white-space:pre-line">{{ $o->get('onepay_summary') }}</p>
    @endif
    @if ($o->get('receipt_check'))
        <p style="margin:0 0 12px;padding:10px 12px;background:#ECEEF5;border-radius:8px;white-space:pre-line"><b>Receipt check (automatic)</b>{{ "\n".$o->get('receipt_check') }}</p>
        @if ($receipt)<p style="margin:0 0 12px;color:#4C5170;font-size:13px">The customer's receipt is attached.</p>@endif
    @endif
    <table cellpadding="6" style="border-collapse:collapse;margin:0 0 12px">
        @foreach ((array) $o->get('items') as $item)
            <tr style="border-bottom:1px solid #DCDFEA">
                <td>{{ $item['product'] }}@if (! empty($item['colour']) || ! empty($item['size'])) ({{ implode(', ', array_filter([$item['colour'] ?? null, $item['size'] ?? null])) }})@endif</td>
                <td>× {{ $item['quantity'] }}</td>
                <td style="text-align:right">{{ \App\Shop\Shop::kip((int) $item['line_total']) }}</td>
            </tr>
        @endforeach
        @if ($o->get('delivery_fee'))
            <tr><td colspan="2">Delivery</td><td style="text-align:right">{{ \App\Shop\Shop::kip((int) $o->get('delivery_fee')) }}</td></tr>
        @endif
        <tr><td colspan="2"><b>Total</b></td><td style="text-align:right"><b>{{ \App\Shop\Shop::kip((int) $o->get('total')) }}</b></td></tr>
    </table>
    @if ($o->get('note'))<p style="margin:0 0 12px">Note: {{ $o->get('note') }}</p>@endif
    @if ($o->get('tracking_number'))<p style="margin:0 0 12px">Tracking number: <b>{{ $o->get('tracking_number') }}</b></p>@endif
    @if ($o->get('cod_amount'))<p style="margin:0 0 12px">Cash for the courier to collect (COD): <b>{{ \App\Shop\Shop::kip((int) $o->get('cod_amount')) }}</b></p>@endif
    @if ($whatsappUrl)
        <p style="margin:0 0 12px"><a href="{{ $whatsappUrl }}" style="display:inline-block;background:#25D366;color:#0B3D20;padding:10px 16px;border-radius:999px;font-weight:700;text-decoration:none">Send the customer a WhatsApp message</a><br>
        <span style="color:#4C5170;font-size:13px">Opens WhatsApp with the message ready, in the customer's language. Check it, then press send.
        @if (\App\Shop\WhatsApp::active())The shop's number also sends the customer these updates by itself: the order's "WhatsApp messages" shows whether this one went. Only send it by hand if it says NOT SENT.@endif</span></p>
    @endif
    <p style="margin:0"><a href="{{ $o->editUrl() }}">Open the order in the control panel</a></p>
</div>
