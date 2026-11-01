<section class="panel my-orders">
    @unless ($all)
        <div class="account-section-head">
            <h2 class="h-sm" style="margin:0">{{ __('site.'.$group.'.mine') }}</h2>
            @if ($count)<a class="small" href="{{ $allUrl }}">{{ __('site.auth.see_all', ['count' => $count]) }} →</a>@endif
        </div>
    @endunless
    @forelse ($rows as $row)
        @include('livewire.partials.account-row')
    @empty
        <p class="muted small">{{ __('site.'.$group.'.account_hint') }}</p>
    @endforelse
    @include('livewire.partials.pager')
    <a class="btn green" style="align-self:flex-start" href="{{ $pageUrl }}">+ {{ __($group === 'sell' ? 'site.sell.add' : 'site.auth.add_post') }}</a>
</section>
