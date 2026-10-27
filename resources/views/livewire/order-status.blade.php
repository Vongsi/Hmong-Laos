{{-- Re-checks every minute while open, so a new status or tracking number shows without reloading. --}}
<div class="stack" style="gap:18px" @if ($order) wire:poll.60s @endif>
    @if (! $order)
        @if ($number !== '' || $token !== '')
            <p class="note warn" role="alert">{{ __('site.shop.order_not_found') }}</p>
        @endif
        {{-- Without its link: find it by order number and phone number, or from the orders this phone has opened. --}}
        <section class="panel find-order">
            <h1 style="font-size:clamp(26px,4vw,36px)">{{ __('site.shop.find_title') }}</h1>
            <p class="muted">{{ __('site.shop.find_intro') }}</p>
            <form wire:submit="find" class="stack" style="gap:10px">
                <label class="field">{{ __('site.shop.find_number') }}
                    <input wire:model="findNumber" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="DAH-…">
                </label>
                <label class="field">{{ __('site.shop.find_phone') }}
                    <input wire:model="findPhone" type="tel" maxlength="40" autocomplete="tel">
                </label>
                @error('findNumber') <p class="error small">{{ $message }}</p> @enderror
                @error('findPhone') <p class="error small">{{ $message }}</p> @enderror
                <button class="btn" type="submit" wire:loading.attr="disabled" style="align-self:flex-start">{{ __('site.shop.find_button') }}</button>
            </form>
        </section>
        <section class="panel device-orders" data-device-orders wire:ignore hidden>
            <h2 class="h-sm">{{ __('site.shop.device_orders') }}</h2>
            <ul class="stack" style="gap:6px;margin:0;padding:0;list-style:none"></ul>
        </section>
        <p><a href="{{ $productsUrl }}">{{ __('site.shop.browse') }}</a></p>
    @else
        {{-- Remembered on this phone (public/js/site.js), for "Find your order". --}}
        <span hidden data-order-remember data-number="{{ $order->get('title') }}" data-date="{{ $order->date()->copy()->setTimezone(\App\Appearance\Background::TIMEZONE)->translatedFormat('j M Y') }}" data-iso="{{ $order->date()->copy()->setTimezone(\App\Appearance\Background::TIMEZONE)->format('Y-m-d') }}" data-total="{{ \App\Shop\Shop::kip((int) $order->get('total')) }}"></span>
        <div class="stack" style="gap:8px">
            <p style="margin:0"><a href="{{ $back['url'] }}">← {{ $back['label'] }}</a></p>
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

        @if ($order->get('payment_method') === 'onepay' && $order->get('payment_status') === 'paid')
            <p class="flash" role="status">{{ __('site.shop.onepay_paid') }}</p>
        @endif

        @if (\App\Livewire\OrderStatus::awaitingPayment($order))
            @if ($order->get('payment_status') === 'rejected')
                {{-- The team didn't accept the payment (app/Listeners/TrackOrderPayment.php). --}}
                <div class="note warn" role="alert">
                    <b>{{ __('site.shop.payment_not_accepted') }}</b>
                    @if ($order->get('payment_reply'))<p style="white-space:pre-line;margin:6px 0 0">{{ $order->get('payment_reply') }}</p>@endif
                    <p style="margin:6px 0 0">{{ __('site.shop.payment_try_again') }}</p>
                </div>
            @endif
            @if ($onepay)
                {{-- BCEL One: this order's QR code (app/Shop/OnePay.php). Checks every few seconds, so the page
                     shows "Paid" as soon as onepay:listen has received the payment. --}}
                <section class="pay-box onepay-box" wire:poll.4s>
                    <h2 class="h-sm">{{ __('site.shop.onepay_title', ['amount' => \App\Shop\Shop::kip($onepayDue)]) }}</h2>
                    @if ($onepayTest)<p class="note small">{{ __('site.shop.onepay_test', ['test' => \App\Shop\Shop::kip($onepayDue), 'total' => \App\Shop\Shop::kip((int) $order->get('total'))]) }}</p>@endif
                    <div class="pay-grid">
                        <div class="onepay-qr" role="img" aria-label="{{ __('site.shop.onepay_qr') }}">{!! $onepay['svg'] !!}</div>
                        <div class="stack" style="gap:10px">
                            <p>{{ __('site.shop.onepay_steps', ['number' => $order->get('title')]) }}</p>
                            <a class="btn red onepay-app" href="onepay://qr/{{ $onepay['code'] }}">{{ __('site.shop.onepay_open_app') }}</a>
                            <p class="muted small">{{ __('site.shop.onepay_valid_until', ['time' => $onepay['expires']->format('H:i')]) }}</p>
                            <p class="onepay-waiting" role="status"><span class="spinner" aria-hidden="true"></span>{{ __('site.shop.onepay_waiting') }}</p>
                        </div>
                    </div>
                </section>
            @elseif ($order->get('payment_method') === 'onepay')
                <p class="note">{{ __('site.shop.no_bank_details') }}</p>
            @elseif ($order->get('payment_method') === 'transfer')
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
                    <form wire:submit="reportPayment" class="stack receipt-form" style="gap:8px">
                        <label class="field">{{ __('site.shop.receipt_label') }}
                            <input type="file" wire:model="receipt" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <p class="muted small" wire:loading wire:target="receipt">{{ __('site.shop.receipt_uploading') }}</p>
                        @error('receipt')
                            <p class="error small">{{ __('site.shop.receipt_invalid') }}</p>
                        @else
                            @if ($receipt && method_exists($receipt, 'isPreviewable') && $receipt->isPreviewable())
                                <img class="receipt-preview" src="{{ $receipt->temporaryUrl() }}" alt="">
                            @endif
                        @enderror
                        <label class="field">{{ __('site.shop.paid_label') }}
                            <input wire:model="paymentNote" maxlength="500">
                        </label>
                        @error('paymentNote') <p class="error small">{{ $message }}</p> @enderror
                        <p class="muted small">{{ __($readingReceipts ? 'site.shop.receipt_kept_checked' : 'site.shop.receipt_kept') }}</p>
                        <button class="btn" type="submit" wire:loading.attr="disabled" wire:target="reportPayment,receipt" style="align-self:flex-start">{{ __('site.shop.paid_button') }}</button>
                    </form>
                </section>
            @else
                <p class="note">{{ __('site.shop.cash_info') }}</p>
            @endif
        @elseif ($order->get('payment_status') === 'reported')
            <p class="note" role="status">{{ __('site.shop.paid_thanks') }}</p>
        @endif

        @if (\App\Livewire\OrderStatus::canCancel($order))
            {{-- The customer can cancel while the order waits for payment. A pop-up asks first; "keep my order" is
                 the default (focused), and tapping outside or Escape keeps it too. --}}
            <p><button type="button" class="btn red" wire:click="$set('confirmingCancel', true)">{{ __('site.shop.cancel_order') }}</button></p>
            @if ($confirmingCancel)
                <div class="modal-backdrop" wire:click.self="$set('confirmingCancel', false)" x-data x-on:keydown.escape.window="$wire.set('confirmingCancel', false)">
                    <section class="modal cancel-box" role="alertdialog" aria-modal="true" aria-labelledby="cancel-title" aria-describedby="cancel-final">
                        <span class="modal-icon" aria-hidden="true">!</span>
                        <h2 class="h-sm" id="cancel-title">{{ __('site.shop.cancel_confirm', ['number' => $order->get('title')]) }}</h2>
                        <p id="cancel-final"><b>{{ __('site.shop.cancel_final') }}</b></p>
                        <p class="muted small">{{ __('site.shop.cancel_paid_warning') }}</p>
                        <label class="field">{{ __('site.shop.cancel_reason') }}
                            <input wire:model="cancelReason" maxlength="300">
                        </label>
                        @error('cancelReason') <p class="error small">{{ $message }}</p> @enderror
                        <div class="modal-actions">
                            <button type="button" class="btn ghost" x-init="$nextTick(() => $el.focus())" wire:click="$set('confirmingCancel', false)">{{ __('site.shop.cancel_no') }}</button>
                            <button type="button" class="btn red" wire:click="cancelOrder" wire:loading.attr="disabled">{{ __('site.shop.cancel_yes') }}</button>
                        </div>
                    </section>
                </div>
            @endif
        @endif

        @if ($history)
            {{-- What the customer sent and what the team answered (App\Shop\Payments::history()). Tap a receipt to
                 see it large (public/js/site.js). --}}
            <section class="panel pay-history" data-receipts data-t-open="{{ __('site.shop.photo_open') }}" data-t-prev="{{ __('site.shop.photo_prev') }}" data-t-next="{{ __('site.shop.photo_next') }}" data-t-zoom="{{ __('site.shop.photo_zoom') }}" data-t-close="{{ __('site.popup.close') }}" data-t-of="{{ __('site.shop.photo_of') }}">
                <h2 class="h-sm">{{ __('site.shop.history_title') }}</h2>
                <ol class="history">
                    @foreach ($history as $step)
                        <li class="history-{{ $step['event'] }}">
                            <small class="muted">{{ $step['time'] }}</small>
                            <b>{{ __('site.shop.history_'.$step['event']) }}</b>
                            @if ($step['note'])
                                <p>{{ $step['event'] === 'rejected' ? __('site.shop.msg_rejected_reason', ['message' => $step['note']]) : '“'.$step['note'].'”' }}</p>
                            @endif
                            @if ($step['receipt'])
                                <button type="button" class="receipt-thumb" aria-label="{{ __('site.shop.history_receipt') }} · {{ __('site.shop.photo_open') }}">
                                    <img src="{{ $step['receipt'] }}" alt="{{ __('site.shop.history_receipt') }}" loading="lazy">
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
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
