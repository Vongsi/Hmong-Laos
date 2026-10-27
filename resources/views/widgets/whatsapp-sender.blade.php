{{-- Inline styles: the control panel's own CSS only has the classes it uses itself. data-state: cp.js reloads this
     while it isn't "ready". --}}
@php($line = 'margin:0 0 8px;font-size:14px')
@php($small = 'margin:0 0 8px;font-size:13px;opacity:.75')
@php($button = 'padding:6px 12px;border-radius:8px;border:1px solid rgba(127,127,127,.45);font-weight:600;font-size:13px;cursor:pointer')
<div data-state="{{ $state }}" style="padding:16px 20px">
    <h2 style="margin:0 0 4px;font-size:16px;font-weight:600">WhatsApp updates to buyers</h2>
    <p style="{{ $small }}">Order received, confirmed, paid, payment not accepted, sent / ready, completed and cancelled go to the buyer's WhatsApp from the shop's own number, in their language (Shop settings → WhatsApp updates). Each order shows its messages under "WhatsApp messages"; when one can't go, you get an email with it ready to send by hand.</p>

    @if ($notice)
        <p style="{{ $line }};padding:8px 10px;border-radius:8px;background:{{ $notice['ok'] ? 'rgba(39,174,96,.15)' : 'rgba(192,57,43,.15)' }}">{{ $notice['text'] }}</p>
    @endif
    @if (! $switchedOn && $state !== 'not_set_up')
        <p style="{{ $line }};color:#c0392b;font-weight:600">Switched off in Shop settings: buyers get no WhatsApp messages.</p>
    @endif

    @switch($state)
        @case('not_set_up')
            <p style="{{ $line }}">Not set up. Set WHATSAPP_SENDER_URL and WHATSAPP_SENDER_TOKEN in the server settings (.env) and run <code>php artisan whatsapp:sender</code> (README: WhatsApp updates).</p>
            @break
        @case('offline')
            <p style="{{ $line }};color:#c0392b;font-weight:600">The WhatsApp sender isn't running, so nothing can be sent.</p>
            <p style="{{ $small }}">Start it with <code>php artisan whatsapp:sender</code> (on the server: Forge → Daemons). {{ $status['error'] ?? '' }}</p>
            @break
        @case('starting')
            <p style="{{ $line }}">Opening WhatsApp… this takes up to a minute.</p>
            @if (! empty($status['error']))<p style="{{ $small }}">{{ $status['error'] }}</p>@endif
            @break
        @case('loading')
            <p style="{{ $line }}"><b style="color:#27ae60">Linked.</b> WhatsApp is loading{{ isset($status['progress']) ? ' ('.$status['progress'].'%)' : '' }}… the first time this can take a few minutes. Keep the phone on and online.</p>
            @if (! empty($status['error']))<p style="{{ $small }}">{{ $status['error'] }}</p>@endif
            @break
        @case('disconnected')
            <p style="{{ $line }};color:#c0392b;font-weight:600">{{ $status['error'] ?? 'Disconnected.' }}</p>
            <p style="{{ $small }}">Starting again: a code to scan appears here shortly.</p>
            @break
        @case('qr')
            <p style="{{ $line }};font-weight:600">Not linked yet. On the shop's phone open WhatsApp → Settings → Linked devices → Link a device, and scan this code.</p>
            @if (! empty($status['qr']))
                <img src="{{ $status['qr'] }}" alt="Code to link WhatsApp" width="264" height="264" style="display:block;margin:4px 0 8px;background:#fff;padding:8px;border-radius:8px">
            @endif
            <p style="{{ $small }}">The code changes every half minute; this box follows. Use a number that is only for the shop, not someone's personal WhatsApp.</p>
            @break
        @case('ready')
            <p style="{{ $line }}">
                <b style="color:#27ae60">Linked</b> to <b>+{{ $status['me']['number'] ?? '?' }}</b>@if (! empty($status['me']['name'])) ({{ $status['me']['name'] }})@endif.
                {{ $status['queued'] ?? 0 }} waiting · {{ $status['sent_today'] ?? 0 }} sent today (at most {{ $status['daily_limit'] ?? '?' }} a day).
            </p>
            @if (! empty($status['dry_run']))
                <p style="{{ $line }};color:#c0392b;font-weight:600">Dry run: nothing really goes to WhatsApp.</p>
            @endif
            @if (! empty($status['paused_until']))
                <p style="{{ $line }};color:#c0392b">Paused until {{ $status['paused_until'] }}.</p>
            @endif
            <div style="display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin:4px 0 8px">
                <form method="POST" action="{{ cp_route('whatsapp.test') }}" style="margin:0;display:flex;gap:6px;align-items:center">
                    @csrf
                    <input name="phone" type="tel" required maxlength="40" placeholder="020 5555 1234" aria-label="Phone number for a test" style="padding:5px 8px;border-radius:8px;border:1px solid rgba(127,127,127,.45);font-size:13px;width:150px;background:transparent;color:inherit">
                    <button type="submit" style="{{ $button }}">Send a test</button>
                </form>
                <form method="POST" action="{{ cp_route('whatsapp.unlink') }}" style="margin:0" onsubmit="return confirm('Unlink this WhatsApp number? Buyers get no messages until a phone is linked again.')">
                    @csrf
                    <button type="submit" style="{{ $button }}">Unlink</button>
                </form>
            </div>
            @break
    @endswitch

    @if (collect($attention ?? [])->isNotEmpty())
        <h3 style="margin:12px 0 4px;font-size:14px;font-weight:600;color:#c0392b">Needs a look ({{ count($attention) }})</h3>
        <p style="{{ $small }}">Not sent in the last 7 days, or sent over a day ago and still not on the buyer's phone (wrong number, phone off, or they haven't opened WhatsApp): resend it, or call them.</p>
        @foreach ($attention as $message)
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:4px 12px;padding:5px 0;border-top:1px solid rgba(127,127,127,.25);font-size:13px">
                <span style="flex:1;min-width:240px">
                    <span style="opacity:.7">{{ $message->at }}</span> ·
                    <a href="{{ $message->url }}" style="text-decoration:underline">{{ $message->what ?: 'Message' }}</a>
                    · {{ $message->to }} ·
                    <span style="color:#c0392b">{{ $message->result === 'failed' ? 'not sent: '.$message->error : 'not delivered yet' }}</span>
                </span>
                <form method="POST" action="{{ cp_route('whatsapp.resend', $message->id) }}" style="margin:0" onsubmit="return confirm('Send this message again, to the same number?')">
                    @csrf
                    <button type="submit" style="{{ $button }};padding:3px 10px">Resend</button>
                </form>
            </div>
        @endforeach
    @endif

    @if (collect($recent)->isNotEmpty())
        <h3 style="margin:12px 0 4px;font-size:14px;font-weight:600">Latest messages <a href="{{ $logUrl }}" style="font-weight:400;font-size:13px;text-decoration:underline;margin-left:6px">All WhatsApp messages</a></h3>
        <p style="{{ $small }}">✓ sent · ✓✓ delivered to their phone · <span style="color:#2f80ed">✓✓</span> read (only when the buyer has read receipts on).</p>
        @foreach ($recent as $message)
            <p style="margin:0;padding:5px 0;border-top:1px solid rgba(127,127,127,.25);font-size:13px">
                <span style="opacity:.7">{{ $message->at }}</span> ·
                <a href="{{ $message->url }}" style="text-decoration:underline">{{ $message->what ?: 'Message' }}</a>
                · {{ $message->to }} ·
                @switch($message->result)
                    @case('read')<span style="color:#2f80ed;font-weight:600">✓✓ read</span>@break
                    @case('delivered')<span style="opacity:.85">✓✓ delivered</span>@break
                    @case('sent')<span style="opacity:.85">✓ sent</span>@break
                    @case('failed')<span style="color:#c0392b">not sent: {{ $message->error }}</span>@break
                    @default<span style="opacity:.8">waiting</span>
                @endswitch
            </p>
        @endforeach
    @endif
</div>
