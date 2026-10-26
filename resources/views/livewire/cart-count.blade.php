<div class="cart-slot">
    @if ($count > 0)
        <a class="cart-btn" href="{{ $url }}" title="{{ __('site.nav.cart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
            <span class="sr">{{ __('site.nav.cart') }}:</span><b>{{ $count }}</b>
        </a>
    @endif
</div>
