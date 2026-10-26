{{-- Inline styles: the control panel's own CSS only has the classes it uses itself. --}}
<div style="padding:16px 20px">
    <h2 style="margin:0 0 4px;font-size:16px;font-weight:600">Waiting for review ({{ $toReview->count() }})</h2>
    <p style="margin:0 0 12px;font-size:13px;opacity:.7">Sent by members through "Sell with us" and "Share", in every language. Open one, check it, then publish it or write a message to the member.</p>
    @forelse ($toReview as $item)
        <p style="margin:0;padding:8px 0;border-top:1px solid rgba(127,127,127,.25);font-size:14px">
            <span style="display:inline-block;min-width:64px;font-size:12px;font-weight:600;opacity:.75">{{ $item->kind }}</span>
            <a href="{{ $item->url }}" style="font-weight:600;text-decoration:underline">{{ $item->title }}</a>
            <span style="font-size:12px;opacity:.7"> · {{ $item->language }} · {{ $item->by }}@if ($item->sent) · {{ $item->sent }}@endif</span>
        </p>
    @empty
        <p style="margin:0;font-size:14px;opacity:.8">Nothing to review right now.</p>
    @endforelse
    @if ($withMember->isNotEmpty())
        <h3 style="margin:16px 0 4px;font-size:14px;font-weight:600">Waiting for the member to make changes ({{ $withMember->count() }})</h3>
        @foreach ($withMember as $item)
            <p style="margin:0;padding:6px 0;border-top:1px solid rgba(127,127,127,.25);font-size:13px;opacity:.85">
                <span style="display:inline-block;min-width:64px;font-size:12px;font-weight:600">{{ $item->kind }}</span>
                <a href="{{ $item->url }}" style="text-decoration:underline">{{ $item->title }}</a>
                <span style="font-size:12px;opacity:.7"> · {{ $item->language }} · {{ $item->by }}</span>
            </p>
        @endforeach
    @endif
</div>
