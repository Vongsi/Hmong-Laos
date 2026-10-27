{{-- Inline styles: the control panel's own CSS only has the classes it uses itself. --}}
<div style="padding:16px 20px">
    <h2 style="margin:0 0 4px;font-size:16px;font-weight:600">Seller payouts</h2>
    <p style="margin:0 0 12px;font-size:13px;opacity:.7">
        What we owe members for their products in delivered, paid orders{{ $fee ? ', less the '.$fee.'% shop fee' : '' }}.
        Prepare a payout, transfer the amount to their account, then open the payout, fill in the date and bank reference and set Paid: they get an email.
        <a href="{{ $listUrl }}" style="text-decoration:underline">All payouts</a>
    </p>
    @forelse ($owed as $seller)
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;padding:10px 0;border-top:1px solid rgba(127,127,127,.25);font-size:14px">
            <div style="flex:1;min-width:220px">
                @if ($seller->userUrl)<a href="{{ $seller->userUrl }}" style="font-weight:600;text-decoration:underline">{{ $seller->name }}</a>@else<b>{{ $seller->name }}</b>@endif
                <span style="font-size:12px;opacity:.7"> · {{ $seller->items }} {{ $seller->items === 1 ? 'item' : 'items' }}</span><br>
                <span style="font-size:12px;{{ $seller->payTo ? 'opacity:.8' : 'color:#c0392b;font-weight:600' }}">{{ $seller->payTo ?? 'No bank account yet: ask them to add it on My account' }}</span>
            </div>
            <b style="font-size:15px">{{ $seller->amount }}</b>
            <form method="POST" action="{{ cp_route('seller-payouts.prepare') }}" style="margin:0">
                @csrf
                <input type="hidden" name="seller" value="{{ $seller->seller }}">
                <button type="submit" style="padding:6px 12px;border-radius:8px;border:1px solid rgba(127,127,127,.45);font-weight:600;font-size:13px;cursor:pointer">Prepare payout</button>
            </form>
        </div>
    @empty
        <p style="margin:0;font-size:14px;opacity:.8">Nothing owed to sellers right now.</p>
    @endforelse
    @if ($toPay->isNotEmpty())
        <h3 style="margin:16px 0 4px;font-size:14px;font-weight:600">To pay: transfer, attach the screenshot, set "Sent" ({{ $toPay->count() }})</h3>
        @foreach ($toPay as $payout)
            <p style="margin:0;padding:6px 0;border-top:1px solid rgba(127,127,127,.25);font-size:13px">
                <a href="{{ $payout->url }}" style="text-decoration:underline">{{ $payout->title }}</a>
                @if ($payout->checkerNote)<br><span style="color:#c0392b;font-weight:600">Sent back by the checker: {{ $payout->checkerNote }}</span>@endif
            </p>
        @endforeach
    @endif
    @if ($toCheck->isNotEmpty())
        <h3 style="margin:16px 0 4px;font-size:14px;font-weight:600">Sent, waiting for a second person to check ({{ $toCheck->count() }})</h3>
        @foreach ($toCheck as $payout)
            <p style="margin:0;padding:6px 0;border-top:1px solid rgba(127,127,127,.25);font-size:13px">
                <a href="{{ $payout->url }}" style="text-decoration:underline">{{ $payout->title }}</a>
                <span style="font-size:12px;opacity:.75"> · sent by {{ $payout->madeBy }}@if ($payout->reference) · ref {{ $payout->reference }}@endif</span>
                @if ($payout->byMe)<br><span style="font-size:12px;opacity:.75">You sent this one: another team member checks it.</span>@endif
            </p>
        @endforeach
    @endif
</div>
