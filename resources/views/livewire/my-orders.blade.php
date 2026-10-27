<div>
    @if ($signedIn)
        <section class="panel my-orders">
            @unless ($all)
                <div class="account-section-head">
                    <h2 class="h-sm" style="margin:0">{{ __('site.shop.my_orders') }}</h2>
                    @if ($count)<a class="small" href="{{ $allUrl }}">{{ __('site.auth.see_all', ['count' => $count]) }} →</a>@endif
                </div>
            @endunless
            @forelse ($orders as $row)
                @include('livewire.partials.account-row')
            @empty
                <p class="muted">{{ __('site.shop.my_orders_empty') }} <a href="{{ $shopUrl }}">{{ __('site.shop.browse') }}</a></p>
            @endforelse
            @include('livewire.partials.pager')
        </section>
    @endif
</div>
