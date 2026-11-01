<div>
    @if ($step === 'form')
        <form class="stack" style="gap:10px" wire:submit="submit">
            @if ($problem)<p class="flash" role="alert">{{ $problem }}</p>@endif
            <label class="field" for="{{ $id }}-name">{{ __('site.auth.name') }}
                <input id="{{ $id }}-name" wire:model="name" maxlength="100" autocomplete="name" placeholder="{{ __('site.auth.ph_name') }}" required>
            </label>
            @error('name') <p class="error small">{{ $message }}</p> @enderror
            <label class="field" for="{{ $id }}-contact">{{ $mobile ? __('site.auth.email_or_mobile') : __('site.auth.email') }}
                <input id="{{ $id }}-contact" wire:model="contact" type="{{ $mobile ? 'text' : 'email' }}" maxlength="150" autocomplete="{{ $mobile ? 'username' : 'email' }}" placeholder="{{ $mobile ? __('site.auth.ph_email_or_mobile') : __('site.auth.ph_email') }}" required>
            </label>
            @if ($mobile)<p class="muted small" style="margin:0">{{ __('site.auth.create_mobile_help') }}</p>@endif
            @error('contact') <p class="error small">{{ $message }}</p> @enderror
            <label class="field" for="{{ $id }}-password">{{ __('site.auth.password') }}
                <input id="{{ $id }}-password" wire:model="password" type="password" minlength="8" autocomplete="new-password" placeholder="{{ __('site.auth.ph_new_password') }}" required>
            </label>
            <label class="field" for="{{ $id }}-password-confirm">{{ __('site.auth.password_confirm') }}
                <input id="{{ $id }}-password-confirm" wire:model="passwordConfirmation" type="password" minlength="8" autocomplete="new-password" placeholder="{{ __('site.auth.ph_password_again') }}" required>
            </label>
            @error('password') <p class="error small">{{ $message }}</p> @enderror
            {{-- Left empty by people; bots that fill it in get nowhere. --}}
            <input type="text" wire:model="website" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button class="btn green" type="submit" wire:loading.attr="disabled" wire:target="submit">{{ __('site.auth.create_button') }}</button>
            <p class="muted small">{{ __('site.auth.agree') }} <a href="{{ $rulesUrl }}">{{ __('site.nav.rules') }}</a><br>{{ __('site.legal.your_data') }} <a href="{{ $privacyUrl }}">{{ __('site.legal.privacy') }}</a></p>
        </form>
    @else
        {{-- A mobile number: the code we sent on WhatsApp proves it's theirs; then the account is made. --}}
        <form class="stack" style="gap:10px" wire:submit="verify">
            <p style="margin:0">{!! __('site.auth.wa_sent', ['number' => '<b>'.e($shownNumber).'</b>']) !!}</p>
            @if ($problem)<p class="error small" role="alert" style="margin:0">{{ $problem }}</p>@endif
            <label class="field" for="{{ $id }}-code">{{ __('site.auth.wa_code') }}
                <input id="{{ $id }}-code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9 ]*" placeholder="123456" required autofocus style="letter-spacing:.3em;font-size:20px">
            </label>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button class="btn green" type="submit" wire:loading.attr="disabled" wire:target="verify">{{ __('site.auth.create_button') }}</button>
                {{-- "Send again" once a minute has passed since the last code. --}}
                <span x-data="{ left: {{ $waitSeconds }} }" x-init="let t = setInterval(() => { if (left > 0) left--; else clearInterval(t) }, 1000)">
                    <button class="btn ghost" type="button" wire:click="resend" x-bind:disabled="left > 0">
                        {{ __('site.auth.wa_resend') }}<span x-show="left > 0" x-text="' (' + left + ')'"></span>
                    </button>
                </span>
                <button class="btn ghost" type="button" wire:click="back">{{ __('site.auth.wa_other_number') }}</button>
            </div>
        </form>
    @endif
</div>
