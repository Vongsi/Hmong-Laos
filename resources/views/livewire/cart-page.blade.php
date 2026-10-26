<div class="stack" style="gap:16px">
    @if ($lines->isEmpty())
        <p class="panel">{{ __('site.shop.cart_empty') }} <a href="{{ $productsUrl }}">{{ __('site.shop.browse') }}</a></p>
    @else
        <ul class="cart-lines">
            @foreach ($lines as $line)
                <li class="cart-line" wire:key="l{{ $line->id }}">
                    @if ($line->image)
                        <img src="{{ $line->image }}" alt="" width="72" height="72">
                    @else
                        <span class="ph c cart-ph"></span>
                    @endif
                    <div class="cart-info">
                        <a href="{{ $line->url }}"><b>{{ $line->title }}</b></a>
                        <span class="muted small">@if ($line->colour){{ __('site.shop.colour') }}: {{ $line->colour }} · @endif @if ($line->size){{ __('site.shop.size') }}: {{ $line->size }} · @endif{{ \App\Shop\Shop::kip($line->unitPrice) }}</span>
                        @if ($line->quantity < $line->requested)
                            <span class="error small">{{ $line->quantity === 0 ? __('site.shop.sold_out') : __('site.shop.only_left', ['count' => $line->available]) }}</span>
                        @endif
                    </div>
                    <div class="cart-actions">
                        <label class="qty">
                            <span class="sr">{{ __('site.shop.quantity') }}</span>
                            <input type="number" inputmode="numeric" min="0" max="{{ $line->available ?? \App\Shop\Cart::MAX_QUANTITY }}" value="{{ $line->quantity }}" wire:change="setQuantity('{{ $line->id }}', $event.target.value)">
                        </label>
                        <b>{{ \App\Shop\Shop::kip($line->lineTotal) }}</b>
                        <button type="button" class="link small" wire:click="remove('{{ $line->id }}')">{{ __('site.shop.remove') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="cart-summary">
            <span>{{ __('site.shop.subtotal') }}</span>
            <b>{{ \App\Shop\Shop::kip($subtotal) }}</b>
        </div>
        <div class="row-wrap" style="justify-content:flex-end">
            <a class="btn ghost" href="{{ $productsUrl }}">{{ __('site.shop.browse') }}</a>
            <a class="btn red" href="{{ $checkoutUrl }}">{{ __('site.shop.checkout') }}</a>
        </div>
    @endif
</div>
