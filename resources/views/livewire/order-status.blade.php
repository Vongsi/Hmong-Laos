{{-- Re-checks every minute while open, so a new status or tracking number shows without reloading. --}}
<div class="stack" style="gap:18px" wire:poll.60s>
    @if (! $order)
        <p class="panel">{{ __('site.shop.order_not_found') }} <a href="{{ $productsUrl }}">{{ __('site.shop.browse') }}</a></p>
    @else
        <div class="stack" style="gap:8px">
            <h1 style="font-size:clamp(26px,4vw,36px)">{{ __('site.shop.order', ['number' => $order->get('title')]) }}</h1>
            @if ($order->get('status') === 'new')
                <p class="flash" role="status">{{ __('site.shop.thanks') }} {{ __('site.shop.keep_link') }}</p>
            @endif
        </div>

        <dl class="facts">
            <dt>{{ __('site.shop.status') }}</dt><dd><b>{{ __('site.shop.statuses.'.$order->get('status')) }}</b></dd>
            <dt>{{ __('site.shop.payment') }}</dt><dd>{{ __('site.shop.payments.'.$order->get('payment_status')) }}</dd>
        </dl>

        @if ($order->get('status') === 'cancelled')
            <p class="note">{{ __('site.shop.cancelled_note') }}</p>
        @else
            <ol class="timeline">
                @foreach ($timeline as $step)
                    <li class="{{ $step['done'] ? 'done' : '' }}" @if ($step['current']) aria-current="step" @endif>
                        <span class="tl-dot" aria-hidden="true">{{ $step['done'] ? '✓' : '' }}</span>
                        <span class="tl-text">
                            <b>{{ $step['label'] }}</b>@if ($step['done'])<span class="sr"> ({{ __('site.shop.step_done') }})</span>@endif
                            @if ($step['time'])<small>{{ $step['time'] }}</small>@endif
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif

        @if (in_array($order->get('status'), ['shipped', 'completed'], true))
            @if ($order->get('delivery') === 'delivery')
                @if ($courier || $order->get('delivery_message'))
                    <section class="panel ship-box">
                        @if ($courier)
                            <h2 class="h-sm">{{ $courier['own'] ? __('site.shop.own_delivery') : __('site.shop.sent_with', ['courier' => $courier['name']]) }}</h2>
                        @endif
                        @if ($order->get('tracking_number'))
                            <div class="tracking">
                                <span class="muted small">{{ __('site.shop.tracking_number') }}</span>
                                <b class="tracking-no">{{ $order->get('tracking_number') }}</b>
                                <button type="button" class="share-btn" data-copy="{{ $order->get('tracking_number') }}" data-done="{{ __('site.shop.copied') }}">{{ __('site.shop.copy') }}</button>
                            </div>
                            @if ($courier['track_url'] ?? null)
                                <div class="stack" style="gap:6px">
                                    <a class="btn" style="align-self:flex-start" href="{{ $courier['track_url'] }}" target="_blank" rel="noopener" data-copy-also="{{ $order->get('tracking_number') }}">{{ __('site.shop.track_on', ['courier' => $courier['name']]) }} ↗</a>
                                    <p class="muted small">{{ __('site.shop.track_hint', ['courier' => $courier['name']]) }}</p>
                                </div>
                            @endif
                        @endif
                        @if ($order->get('cod_amount') && $order->get('status') === 'shipped' && $order->get('payment_status') !== 'paid')
                            <p><b>{{ __('site.shop.cod_ready', ['amount' => \App\Shop\Shop::kip((int) $order->get('cod_amount'))]) }}</b></p>
                        @endif
                        @if ($order->get('delivery_message'))
                            <p class="note">{{ $order->get('delivery_message') }}</p>
                        @endif
                    </section>
                @endif
            @else
                <section class="panel ship-box">
                    <h2 class="h-sm">{{ __('site.shop.step_ready') }}</h2>
                    @if ($pickupPlace)<p>{{ __('site.shop.pickup_at', ['place' => $pickupPlace]) }}</p>@endif
                    @if ($order->get('delivery_message'))<p class="note">{{ $order->get('delivery_message') }}</p>@endif
                </section>
            @endif
        @endif

        @if ($order->get('payment_status') === 'pending' && $order->get('status') !== 'cancelled')
            @if ($order->get('payment_method') === 'transfer')
                <section class="pay-box">
                    <h2 class="h-sm">{{ __('site.shop.pay_now', ['amount' => \App\Shop\Shop::kip((int) $order->get('total'))]) }}</h2>
                    <div class="pay-grid">
                        @if ($qrUrl)
                            <img class="qr" src="{{ $qrUrl }}" alt="QR" width="220" height="220">
                        @endif
                        <div class="stack" style="gap:10px">
                            <p>{{ __($qrUrl ? 'site.shop.scan_qr' : 'site.shop.transfer_to', ['number' => $order->get('title')]) }}</p>
                            @if (array_filter($bank))
                                <dl class="facts">
                                    @if ($bank['bank'])<dt>{{ __('site.shop.bank') }}</dt><dd>{{ $bank['bank'] }}</dd>@endif
                                    @if ($bank['account_name'])<dt>{{ __('site.shop.account_name') }}</dt><dd>{{ $bank['account_name'] }}</dd>@endif
                                    @if ($bank['account_number'])<dt>{{ __('site.shop.account_number') }}</dt><dd style="user-select:all">{{ $bank['account_number'] }}</dd>@endif
                                </dl>
                            @elseif (! $qrUrl)
                                <p class="muted">{{ __('site.shop.no_bank_details') }}</p>
                            @endif
                            @if ($instructions)<p class="muted small">{{ $instructions }}</p>@endif
                        </div>
                    </div>
                    <form wire:submit="reportPayment" class="stack" style="gap:8px">
                        <label class="field">{{ __('site.shop.paid_label') }}
                            <input wire:model="paymentNote" maxlength="500">
                        </label>
                        @error('paymentNote') <p class="error small">{{ $message }}</p> @enderror
                        <button class="btn" type="submit" wire:loading.attr="disabled" style="align-self:flex-start">{{ __('site.shop.paid_button') }}</button>
                    </form>
                </section>
            @else
                <p class="note">{{ __('site.shop.cash_info') }}</p>
            @endif
        @elseif ($order->get('payment_status') === 'reported')
            <p class="note" role="status">{{ __('site.shop.paid_thanks') }}</p>
        @endif

        <section class="panel">
            @foreach ((array) $order->get('items') as $item)
                <div class="mini">
                    <span>{{ $item['product'] }}@if (! empty($item['colour']) || ! empty($item['size'])) ({{ implode(', ', array_filter([$item['colour'] ?? null, $item['size'] ?? null])) }})@endif × {{ $item['quantity'] }}</span>
                    <span>{{ \App\Shop\Shop::kip((int) $item['line_total']) }}</span>
                </div>
            @endforeach
            @if ($order->get('delivery_fee'))
                <div class="mini"><span>{{ __('site.shop.delivery_fee') }}</span><span>{{ \App\Shop\Shop::kip((int) $order->get('delivery_fee')) }}</span></div>
            @endif
            <div class="mini total"><b>{{ __('site.shop.total') }}</b><b>{{ \App\Shop\Shop::kip((int) $order->get('total')) }}</b></div>
            <p class="muted small">{{ $order->get('delivery') === 'delivery' ? __('site.shop.delivery').': '.$order->get('address') : __('site.shop.pickup') }}</p>
        </section>
    @endif
</div>
