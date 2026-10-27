{{-- One row in My account's lists (orders, posts, products): what it is on the left, where it stands on the right. --}}
<a class="order-row" href="{{ $row['url'] }}">
    <span class="order-main">
        <b>{{ $row['title'] }}</b>
        @if ($row['line'])<span class="order-items">{{ $row['line'] }}</span>@endif
        <small class="muted">{{ $row['meta'] }}</small>
    </span>
    <span class="order-side">
        <span class="badge {{ $row['tone'] }}">{{ $row['badge'] }}</span>
        @if ($row['action'])
            <span class="order-action">{{ $row['action'] }} →</span>
        @elseif ($row['note'])
            <small class="muted">{{ $row['note'] }}</small>
        @endif
    </span>
</a>
