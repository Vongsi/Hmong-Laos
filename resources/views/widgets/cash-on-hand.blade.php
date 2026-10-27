{{-- Inline styles: the control panel's own CSS only has the classes it uses itself. --}}
<div style="padding:16px 20px">
    <h2 style="margin:0 0 4px;font-size:16px;font-weight:600">Cash not handed in yet @if ($people->isNotEmpty())({{ $total }})@endif</h2>
    <p style="margin:0 0 12px;font-size:13px;opacity:.7">Cash from orders set to Paid (at pick-up, or collected by the courier) that hasn't reached the shop yet. Open a payment and tick "Cash handed in" once it has.</p>
    @forelse ($people as $person)
        <div style="padding:8px 0;border-top:1px solid rgba(127,127,127,.25);font-size:14px">
            <b>{{ $person->who }}</b> <span style="opacity:.8">· {{ $person->amount }}</span>
            @foreach ($person->payments as $payment)
                <p style="margin:2px 0 0 12px;font-size:13px">
                    <a href="{{ $payment->url }}" style="text-decoration:underline">{{ $payment->title }}</a>
                    @if ($payment->since)<span style="font-size:12px;opacity:.7"> · since {{ $payment->since }}</span>@endif
                </p>
            @endforeach
        </div>
    @empty
        <p style="margin:0;font-size:14px;opacity:.8">All cash has been handed in.</p>
    @endforelse
</div>
