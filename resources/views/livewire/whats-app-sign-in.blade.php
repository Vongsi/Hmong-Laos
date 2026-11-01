<div>
    @if ($enabled)
        @if ($mode === 'account')
            {{-- My account → Sign-in methods: the WhatsApp number they sign in with. --}}
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                <span><b>WhatsApp</b> · <span class="muted small">{{ $currentShown ? $currentShown.' · '.__('site.auth.connected') : __('site.auth.not_connected') }}</span></span>
                @if ($step === 'start')
                    <span style="display:flex;gap:8px;flex-wrap:wrap">
                        <button type="button" class="btn {{ $current ? 'ghost' : 'green' }}" style="padding:6px 14px;font-size:14px" wire:click="open">{{ $current ? __('site.auth.wa_change') : __('site.auth.wa_add') }}</button>
                        @if ($current && $canRemove)
                            <button type="button" class="btn red" style="padding:6px 14px;font-size:14px" wire:click="remove" wire:confirm="{{ __('site.auth.wa_remove_confirm') }}">{{ __('site.auth.disconnect') }}</button>
                        @elseif ($current)
                            <span class="muted small">{{ __('site.auth.only_sign_in') }}</span>
                        @endif
                    </span>
                @endif
            </div>
        @elseif ($step === 'start')
            <button type="button" class="sso" wire:click="open">
                <span class="ico" style="background:#25D366" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .2-1.2c-.1-.1-.3-.2-.5-.3z"/></svg>
                </span>
                <span>{{ __('site.auth.wa_continue') }}</span>
            </button>
        @endif

        @if ($step === 'number')
            <form class="stack" style="gap:10px;margin-top:10px" wire:submit="sendCode">
                <label class="field" for="{{ $id }}-number">{{ __('site.auth.wa_number') }}
                    <input id="{{ $id }}-number" wire:model="number" type="tel" inputmode="tel" autocomplete="tel" maxlength="40" placeholder="020 5555 1234" required autofocus>
                </label>
                <p class="muted small" style="margin:0">{{ __('site.auth.wa_number_help') }}</p>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <button class="btn green" type="submit" wire:loading.attr="disabled" wire:target="sendCode">{{ __('site.auth.wa_send') }}</button>
                    <button class="btn red" type="button" wire:click="$set('step', 'start')">{{ __('site.auth.wa_cancel') }}</button>
                </div>
            </form>
        @elseif ($step === 'code')
            <form class="stack" style="gap:10px;margin-top:10px" wire:submit="verify">
                <p style="margin:0">{!! __('site.auth.wa_sent', ['number' => '<b>'.e($sentToShown).'</b>']) !!}</p>
                <label class="field" for="{{ $id }}-code">{{ __('site.auth.wa_code') }}
                    <input id="{{ $id }}-code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9 ]*" placeholder="{{ __('site.ph.code') }}" required autofocus style="letter-spacing:.3em;font-size:20px">
                </label>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <button class="btn green" type="submit" wire:loading.attr="disabled" wire:target="verify">{{ $mode === 'account' ? __('site.auth.wa_confirm') : __('site.auth.sign_in') }}</button>
                    {{-- "Send again" once a minute has passed since the last code. --}}
                    <span x-data="{ left: {{ $waitSeconds }} }" x-init="let t = setInterval(() => { if (left > 0) left--; else clearInterval(t) }, 1000)">
                        <button class="btn ghost" type="button" wire:click="resend" x-bind:disabled="left > 0">
                            {{ __('site.auth.wa_resend') }}<span x-show="left > 0" x-text="' (' + left + ')'"></span>
                        </button>
                    </span>
                    <button class="btn ghost" type="button" wire:click="changeNumber">{{ __('site.auth.wa_other_number') }}</button>
                </div>
            </form>
        @elseif ($step === 'name')
            <form class="stack" style="gap:10px;margin-top:10px" wire:submit="join">
                <p style="margin:0"><b>{{ __('site.auth.wa_welcome') }}</b></p>
                <label class="field" for="{{ $id }}-name">{{ __('site.auth.name') }}
                    <input id="{{ $id }}-name" wire:model="name" maxlength="100" autocomplete="name" placeholder="{{ __('site.auth.ph_name') }}" required autofocus>
                </label>
                @error('name') <p class="error small" style="margin:0">{{ $message }}</p> @enderror
                <button class="btn green" type="submit" wire:loading.attr="disabled" wire:target="join" style="align-self:flex-start">{{ __('site.auth.create_button') }}</button>
            </form>
        @endif

        @if ($problem)<p class="error small" role="alert" style="margin:8px 0 0">{{ $problem }}</p>@endif
        @if ($notice)<p class="flash small" role="status" style="margin:8px 0 0">{{ $notice }}</p>@endif
    @endif
</div>
