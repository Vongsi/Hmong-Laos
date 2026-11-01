<div>
    @if ($lines->isEmpty())
        <p class="panel">{{ __('site.shop.cart_empty') }} <a href="{{ $productsUrl }}">{{ __('site.shop.browse') }}</a></p>
    @else
        <form class="checkout" wire:submit="placeOrder">
            <div class="stack" style="gap:14px">
                <h2 class="h-sm">{{ __('site.shop.your_details') }}</h2>
                <label class="field">{{ __('site.shop.name') }}
                    <input wire:model="name" maxlength="100" autocomplete="name" placeholder="{{ __('site.auth.ph_name') }}">
                </label>
                @error('name') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.shop.phone') }}
                    <input type="tel" wire:model="phone" maxlength="40" autocomplete="tel" placeholder="{{ __('site.ph.phone') }}">
                </label>
                @if ($whatsappUpdates)<p class="small muted" style="margin-top:-8px">{{ __('site.shop.phone_whatsapp') }}</p>@endif
                @error('phone') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.shop.email') }}
                    <input type="email" wire:model="email" maxlength="150" autocomplete="email" placeholder="{{ __('site.auth.ph_email') }}">
                </label>
                @error('email') <p class="error small">{{ $message }}</p> @enderror

                <fieldset class="choice">
                    <legend>{{ __('site.shop.how_receive') }}</legend>
                    <label class="option">
                        <input type="radio" wire:model.live="delivery" value="pickup">
                        <span>{{ __('site.shop.pickup') }}@if ($pickupPlace)<small>{{ __('site.shop.pickup_at', ['place' => $pickupPlace]) }}</small>@endif</span>
                    </label>
                    <label class="option">
                        <input type="radio" wire:model.live="delivery" value="delivery">
                        <span>{{ __('site.shop.delivery') }}@if ($deliveryFee)<small>+ {{ \App\Shop\Shop::kip($deliveryFee) }}</small>@endif</span>
                    </label>
                </fieldset>
                @if ($delivery === 'delivery')
                    <label class="field">{{ __('site.shop.address') }}
                        <textarea wire:model="address" rows="3" maxlength="500" autocomplete="street-address" placeholder="{{ __('site.ph.address') }}"></textarea>
                    </label>
                    @error('address') <p class="error small">{{ $message }}</p> @enderror
                @endif

                <fieldset class="choice">
                    <legend>{{ __('site.shop.how_pay') }}</legend>
                    @foreach ($methods as $method)
                        <label class="option">
                            <input type="radio" wire:model="payment" value="{{ $method }}">
                            <span>{{ __('site.shop.pay_'.$method) }}</span>
                        </label>
                    @endforeach
                </fieldset>
                @error('payment') <p class="error small">{{ $message }}</p> @enderror

                <label class="field">{{ __('site.shop.note') }}
                    <textarea wire:model="note" rows="2" maxlength="1000" placeholder="{{ __('site.ph.order_note') }}"></textarea>
                </label>
                @error('note') <p class="error small">{{ $message }}</p> @enderror
            </div>

            <aside class="panel order-summary">
                <h3>{{ __('site.shop.cart') }}</h3>
                @foreach ($lines as $line)
                    <div class="mini">
                        <span>{{ $line->title }}@if ($line->colour || $line->size) ({{ implode(', ', array_filter([$line->colour, $line->size])) }})@endif × {{ $line->quantity }}</span>
                        <span>{{ \App\Shop\Shop::kip($line->lineTotal) }}</span>
                    </div>
                @endforeach
                <div class="mini"><span>{{ __('site.shop.subtotal') }}</span><span>{{ \App\Shop\Shop::kip($subtotal) }}</span></div>
                @if ($fee)
                    <div class="mini"><span>{{ __('site.shop.delivery_fee') }}</span><span>{{ \App\Shop\Shop::kip($fee) }}</span></div>
                @endif
                <div class="mini total"><b>{{ __('site.shop.total') }}</b><b>{{ \App\Shop\Shop::kip($total) }}</b></div>
                @error('order') <p class="error small" role="alert">{{ $message }}</p> @enderror
                <button class="btn green" type="submit" wire:loading.attr="disabled">{{ __('site.shop.place_order') }}</button>
                <a class="link small" href="{{ $cartUrl }}">← {{ __('site.shop.cart') }}</a>
            </aside>
        </form>
    @endif
</div>
