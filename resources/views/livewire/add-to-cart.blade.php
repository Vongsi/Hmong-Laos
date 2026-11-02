<form class="buy" wire:submit="add">
    @if ($available === 0)
        <p class="stock out">{{ __('site.shop.sold_out') }}</p>
    @else
        <p class="stock">{{ $available === null ? __('site.shop.made_to_order') : __('site.shop.in_stock', ['count' => $available]) }}</p>
        @if ($colours)
            {{-- data-colour-choice: site.js shows this colour's photo in the gallery. --}}
            <fieldset class="sizes colours">
                <legend class="small muted">{{ __('site.shop.colour') }}</legend>
                @foreach ($colours as $c)
                    <label class="size"><input type="radio" wire:model="colour" value="{{ $c['slug'] }}" data-colour-choice><span><i class="swatch" style="--swatch:{{ $c['swatch'] ?: 'transparent' }}" aria-hidden="true"></i>{{ $c['name'] }}</span></label>
                @endforeach
            </fieldset>
            @error('colour') <p class="error small">{{ $message }}</p> @enderror
        @endif
        @if ($sizes)
            <fieldset class="sizes">
                <legend class="small muted">{{ __('site.shop.size') }}</legend>
                @foreach ($sizes as $s)
                    <label class="size"><input type="radio" wire:model="size" value="{{ $s }}"><span>{{ $s }}</span></label>
                @endforeach
            </fieldset>
            @error('size') <p class="error small">{{ $message }}</p> @enderror
        @endif
        <div class="buy-row">
            <label class="qty">
                <span class="sr">{{ __('site.shop.quantity') }}</span>
                <input type="number" inputmode="numeric" min="1" max="{{ $available ?? \App\Shop\Cart::MAX_QUANTITY }}" wire:model="quantity">
            </label>
            <button class="btn green" type="submit" wire:loading.attr="disabled">{{ __('site.shop.add') }}</button>
        </div>
        @error('quantity') <p class="error small">{{ $message }}</p> @enderror
        @if ($added)
            <p class="flash small" role="status">{{ __('site.shop.added') }} <a href="{{ $cartUrl }}">{{ __('site.shop.view_cart') }} →</a></p>
        @endif
    @endif
</form>
