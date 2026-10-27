<div>
    @if ($show)
        <section class="panel my-orders">
            @unless ($all)
                <div class="account-section-head">
                    <h2 class="h-sm" style="margin:0">{{ __('site.payouts.mine') }}</h2>
                    @if ($count)<a class="small" href="{{ $allUrl }}">{{ __('site.auth.see_all', ['count' => $count]) }} →</a>@endif
                </div>
            @endunless
            <div class="earn-summary">
                <span><b>{{ __('site.payouts.waiting', ['amount' => \App\Shop\Shop::kip($waiting)]) }}</b>@if ($waitingItems) <span class="muted small">({{ trans_choice('site.payouts.waiting_items', $waitingItems, ['count' => $waitingItems]) }})</span>@endif</span>
                <span class="muted">{{ __('site.payouts.paid_total', ['amount' => \App\Shop\Shop::kip($paid)]) }}</span>
            </div>
            @if ($fee)<p class="muted small" style="margin:0 0 8px">{{ __('site.payouts.fee_note', ['fee' => $fee]) }}</p>@endif
            @if ($needsAccount)<p style="margin:0 0 8px"><a class="order-action" href="{{ $accountUrl }}">{{ __('site.payouts.add_account') }} →</a></p>@endif
            @forelse ($rows as $row)
                @include('livewire.partials.account-row')
            @empty
                <p class="muted small">{{ __('site.payouts.none') }}</p>
            @endforelse
            @include('livewire.partials.pager')
        </section>
    @endif
</div>
