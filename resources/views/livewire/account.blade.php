<div class="account-top">
    @if (! $user)
        <p class="panel">{{ __('site.auth.sign_in_first') }} <a href="{{ $joinUrl }}">{{ __('site.nav.join') }}</a></p>
    @else
        <form class="join-card account-details" wire:submit="saveProfile">
            <div>
                <h2 class="h-sm" style="margin:0">{{ __('site.auth.profile') }}</h2>
                <p class="muted small" style="margin:4px 0 0">{{ $noEmailVia ? __('site.auth.signed_in_with', ['provider' => $noEmailVia]) : __('site.auth.signed_in_as', ['email' => $user->email]) }}</p>
            </div>
            {{-- Profile photo: chosen and cropped in a window (public/js/photo-crop.js), saved by savePhoto(); shown here
                 and on the Members page (App\Support\ProfilePhoto). --}}
            <div class="photo-row" x-data="photoCrop($wire)">
                <span class="avatar avatar-lg tone-{{ $user->id % 4 }}">
                    @if ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="{{ __('site.auth.photo') }}" width="88" height="88">
                    @else
                        {{ mb_strtoupper(mb_substr($user->name ?: '?', 0, 2)) }}
                    @endif
                </span>
                <div class="stack" style="gap:6px;flex:1 1 220px">
                    <b>{{ __('site.auth.photo') }}</b>
                    <div class="row-wrap" style="align-items:center">
                        <label class="btn {{ $photoUrl ? 'ghost' : 'green' }}" style="padding:7px 14px;font-size:14px">
                            {{ $photoUrl ? __('site.auth.photo_change') : __('site.auth.photo_add') }}
                            <input type="file" accept="image/*" class="sr-only" x-on:change="pick($event)">
                        </label>
                        @if ($photoUrl)
                            <button type="button" class="link small" style="color:var(--red)" wire:click="removePhoto" wire:confirm="{{ __('site.auth.photo_remove_confirm') }}">{{ __('site.auth.photo_remove') }}</button>
                        @endif
                    </div>
                    <p class="muted small" style="margin:0">{{ __('site.auth.photo_help') }}</p>
                    @if ($photoNotice)<p class="flash small" role="status" style="margin:0">{{ $photoNotice }}</p>@endif
                    @error('photo') <p class="error small" style="margin:0">{{ $message }}</p> @enderror
                    <p class="error small" style="margin:0" x-show="failed" x-cloak>{{ __('site.auth.photo_error') }}</p>
                </div>
                <dialog class="crop-dialog" x-ref="dialog" wire:ignore aria-labelledby="crop-title" x-on:close="close()">
                    <h2 class="h-sm" id="crop-title" style="margin:0">{{ __('site.auth.photo_crop_title') }}</h2>
                    <canvas x-ref="stage" class="crop-stage" width="600" height="600" tabindex="0" aria-label="{{ __('site.auth.photo_crop_help') }}"
                            x-on:pointerdown="down($event)" x-on:pointermove="drag($event)" x-on:pointerup="up($event)" x-on:pointercancel="up($event)"
                            x-on:wheel.prevent="wheel($event)" x-on:keydown="key($event)"></canvas>
                    <label class="field">{{ __('site.auth.photo_zoom') }}
                        <input type="range" min="1" max="4" step="0.01" x-bind:value="zoom" x-on:input="setZoom($event.target.value)">
                    </label>
                    <p class="muted small" style="margin:0">{{ __('site.auth.photo_crop_help') }}</p>
                    <p class="error small" style="margin:0" x-show="failed" x-cloak>{{ __('site.auth.photo_error') }}</p>
                    <div class="row-wrap">
                        <button type="button" class="btn green" x-on:click="save()" x-bind:disabled="busy">{{ __('site.auth.photo_save') }}</button>
                        <button type="button" class="btn red" x-on:click="close()" x-bind:disabled="busy">{{ __('site.auth.photo_cancel') }}</button>
                    </div>
                </dialog>
            </div>
            <div class="form-grid">
                <label class="field">{{ __('site.auth.name') }}
                    <input wire:model="name" maxlength="100" autocomplete="name" placeholder="{{ __('site.auth.ph_name') }}">
                    @error('name') <span class="error small">{{ $message }}</span> @enderror
                </label>
                <label class="field">{{ __('site.auth.phone') }}
                    <input wire:model="phone" type="tel" maxlength="40" autocomplete="tel" placeholder="{{ $inLaos ? '020 …' : '+…' }}">
                    {{-- Numbers without a country code are taken as Lao (App\Shop\WhatsApp::number). --}}
                    @unless ($inLaos) <span class="muted small">{{ __('site.auth.phone_abroad') }}</span> @endunless
                    @error('phone') <span class="error small">{{ $message }}</span> @enderror
                </label>
                @if ($noEmailVia)
                    {{-- Joined with Facebook without an email (App\Auth\SocialSignIn): add one, optional. --}}
                    <label class="field">{{ __('site.auth.add_email') }}
                        <input wire:model="email" type="email" maxlength="150" autocomplete="email" placeholder="{{ __('site.auth.ph_email') }}">
                        <span class="muted small">{{ __('site.auth.add_email_help', ['provider' => $noEmailVia]) }}</span>
                        @error('email') <span class="error small">{{ $message }}</span> @enderror
                    </label>
                @endif
                {{-- Laos, then where many Hmong live, then every country; the province is only asked in Laos. --}}
                <label class="field">{{ __('site.auth.country') }}
                    <select wire:model.live="country">
                        <option value="">—</option>
                        <option value="{{ \App\Support\Countries::LAOS }}">{{ $countries[\App\Support\Countries::LAOS] }}</option>
                        <optgroup label="{{ __('site.auth.country_common') }}">
                            @foreach ($commonCountries as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="{{ __('site.auth.country_all') }}">
                            @foreach ($countries as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    @error('country') <span class="error small">{{ $message }}</span> @enderror
                </label>
                @if ($inLaos)
                    <label class="field">{{ __('site.auth.province') }}
                        <select wire:model="province">
                            <option value="">—</option>
                            @foreach ($provinces as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                            @if ($province !== '' && ! $provinces->has($province))
                                <option value="{{ $province }}">{{ $province }}</option>
                            @endif
                        </select>
                    </label>
                @endif
                <label class="field">{{ $inLaos ? __('site.auth.area') : __('site.auth.area_abroad') }}
                    <input wire:model="area" maxlength="100" placeholder="{{ $inLaos ? __('site.ph.area') : __('site.ph.area_abroad') }}">
                </label>
                <label class="field">{{ __('site.auth.clan') }}
                    <input wire:model="clan" maxlength="60" placeholder="{{ __('site.ph.clan') }}">
                </label>
                <label class="field">{{ __('site.auth.language') }}
                    <select wire:model="preferredLang">
                        <option value="">—</option>
                        <option value="hm">Hmoob</option>
                        <option value="lo">ລາວ</option>
                        <option value="en">English</option>
                    </select>
                </label>
            </div>
            <p class="muted small" style="margin:0">{{ __('site.auth.private_help') }}</p>
            <label class="check">
                <input type="checkbox" wire:model="listed">
                <span>{{ __('site.members.listed') }}</span>
            </label>
            <p class="muted small" style="margin:0">{{ __('site.members.listed_help') }} <a href="{{ $membersUrl }}">{{ __('site.nav.members') }}</a></p>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button class="btn green" type="submit" wire:loading.attr="disabled">{{ __('site.auth.save') }}</button>
                @if ($saved)<span class="flash small" role="status" style="margin:0">{{ __('site.auth.saved') }}</span>@endif
            </div>
        </form>

        <div class="account-side">
        {{-- Open beside the details on computers, folded on phones. wire:ignore.self keeps it as the member left it
             while Livewire redraws what is inside. --}}
        <details class="join-card account-password" wire:ignore.self x-data x-init="if (window.matchMedia('(min-width: 861px)').matches) $el.open = true">
            <summary><h2 class="h-sm" style="margin:0">{{ __('site.auth.change_password') }}</h2></summary>
            <form class="stack" style="gap:14px" wire:submit="changePassword">
                @if ($askCurrentPassword)
                    <label class="field">{{ __('site.auth.current_password') }}
                        <input type="password" wire:model="currentPassword" autocomplete="current-password" placeholder="{{ __('site.auth.ph_password') }}">
                    </label>
                    @error('currentPassword') <p class="error small">{{ $message }}</p> @enderror
                @else
                    <p class="muted small" style="margin:0">{{ __('site.auth.no_current_password') }}</p>
                @endif
                <label class="field">{{ __('site.auth.new_password') }}
                    <input type="password" wire:model="newPassword" autocomplete="new-password" placeholder="{{ __('site.auth.ph_new_password') }}">
                </label>
                @error('newPassword') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.auth.password_confirm') }}
                    <input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" placeholder="{{ __('site.auth.ph_password_again') }}">
                </label>
                <p class="muted small">{{ __('site.auth.password_rules') }}@if ($askCurrentPassword) {{ __('site.auth.social_password') }}@endif</p>
                <button class="btn green" type="submit" wire:loading.attr="disabled" style="align-self:flex-start">{{ __('site.auth.change_password') }}</button>
                @if ($passwordChanged)<p class="flash small" role="status">{{ __('site.auth.password_changed') }}</p>@endif
            </form>
        </details>

        @if ($signIns || $whatsappSignIn)
            {{-- Facebook and Google: connect to sign in with them too, or disconnect (App\Auth\SocialSignIn; the links
                 and the DELETE are Statamic's own OAuth routes, which may first ask to confirm it's them). WhatsApp:
                 the number they sign in with, confirmed by a code (App\Livewire\WhatsAppSignIn). --}}
            <div id="sign-in" class="join-card">
                <div>
                    <h2 class="h-sm" style="margin:0">{{ __('site.auth.sign_in_methods') }}</h2>
                    <p class="muted small" style="margin:4px 0 0">{{ __('site.auth.sign_in_methods_help') }}</p>
                </div>
                @if ($signInNotice)
                    <p class="{{ $signInNotice['ok'] ? 'flash' : 'error' }} small" role="status" style="margin:0">{{ $signInNotice['text'] }}</p>
                @endif
                @foreach ($signIns as $signIn)
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                        <span><b>{{ $signIn->label }}</b> · <span class="muted small">{{ $signIn->connected ? __('site.auth.connected') : __('site.auth.not_connected') }}</span></span>
                        @if (! $signIn->connected)
                            <a class="btn green" style="padding:6px 14px;font-size:14px" href="{{ $signIn->connectUrl }}">{{ __('site.auth.connect', ['provider' => $signIn->label]) }}</a>
                        @elseif ($signIn->canDisconnect)
                            <form method="POST" action="{{ $signIn->disconnectUrl }}" style="margin:0" onsubmit="return confirm(@js(__('site.auth.disconnect_confirm', ['provider' => $signIn->label])))">
                                @csrf
                                @method('DELETE')
                                <button class="btn red" style="padding:6px 14px;font-size:14px" type="submit">{{ __('site.auth.disconnect') }}</button>
                            </form>
                        @else
                            <span class="muted small">{{ __('site.auth.only_sign_in') }}</span>
                        @endif
                    </div>
                @endforeach
                @if ($whatsappSignIn)
                    <livewire:whats-app-sign-in mode="account" :key="'wa-sign-in-account'" />
                @endif
            </div>
        @endif

        @if ($isSeller)
            {{-- Where the team sends their money for products sold (App\Shop\SellerPayouts). --}}
            <form id="payout" class="join-card" wire:submit="savePayout">
                <div>
                    <h2 class="h-sm" style="margin:0">{{ __('site.payouts.account_title') }}</h2>
                    <p class="muted small" style="margin:4px 0 0">{{ __('site.payouts.account_help') }}</p>
                </div>
                <label class="field">{{ __('site.payouts.bank') }}
                    <input wire:model="payoutBank" maxlength="60" placeholder="BCEL, LDB, JDB…">
                </label>
                <label class="field">{{ __('site.payouts.account_name') }}
                    <input wire:model="payoutAccountName" maxlength="100" placeholder="{{ __('site.ph.account_name') }}">
                </label>
                <label class="field">{{ __('site.payouts.account_number') }}
                    <input wire:model="payoutAccountNumber" inputmode="numeric" maxlength="40" placeholder="{{ __('site.ph.account_number') }}">
                    @error('payoutAccountNumber') <span class="error small">{{ $message }}</span> @enderror
                </label>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <button class="btn green" type="submit" wire:loading.attr="disabled">{{ __('site.payouts.save') }}</button>
                    @if ($payoutSaved)<span class="flash small" role="status" style="margin:0">{{ __('site.auth.saved') }}</span>@endif
                </div>
            </form>
        @endif
        </div>
    @endif
</div>
