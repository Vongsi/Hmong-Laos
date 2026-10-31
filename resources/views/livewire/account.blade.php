<div class="account-top">
    @if (! $user)
        <p class="panel">{{ __('site.auth.sign_in_first') }} <a href="{{ $joinUrl }}">{{ __('site.nav.join') }}</a></p>
    @else
        <form class="join-card account-details" wire:submit="saveProfile">
            <div>
                <h2 class="h-sm" style="margin:0">{{ __('site.auth.profile') }}</h2>
                <p class="muted small" style="margin:4px 0 0">{{ $noEmailVia ? __('site.auth.signed_in_with', ['provider' => $noEmailVia]) : __('site.auth.signed_in_as', ['email' => $user->email]) }}</p>
            </div>
            <div class="form-grid">
                <label class="field">{{ __('site.auth.name') }}
                    <input wire:model="name" maxlength="100" autocomplete="name">
                    @error('name') <span class="error small">{{ $message }}</span> @enderror
                </label>
                <label class="field">{{ __('site.auth.phone') }}
                    <input wire:model="phone" type="tel" maxlength="40" autocomplete="tel" placeholder="020 …">
                    @error('phone') <span class="error small">{{ $message }}</span> @enderror
                </label>
                @if ($noEmailVia)
                    {{-- Joined with Facebook without an email (App\Auth\SocialSignIn): add one, optional. --}}
                    <label class="field">{{ __('site.auth.add_email') }}
                        <input wire:model="email" type="email" maxlength="150" autocomplete="email">
                        <span class="muted small">{{ __('site.auth.add_email_help') }}</span>
                        @error('email') <span class="error small">{{ $message }}</span> @enderror
                    </label>
                @endif
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
                <label class="field">{{ __('site.auth.area') }}
                    <input wire:model="area" maxlength="100">
                </label>
                <label class="field">{{ __('site.auth.clan') }}
                    <input wire:model="clan" maxlength="60">
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
                <button class="btn" type="submit" wire:loading.attr="disabled">{{ __('site.auth.save') }}</button>
                @if ($saved)<span class="flash small" role="status" style="margin:0">{{ __('site.auth.saved') }}</span>@endif
            </div>
        </form>

        <div class="account-side">
        {{-- Open beside the details on computers, folded on phones. wire:ignore.self keeps it as the member left it
             while Livewire redraws what is inside. --}}
        <details class="join-card account-password" wire:ignore.self x-data x-init="if (window.matchMedia('(min-width: 861px)').matches) $el.open = true">
            <summary><h2 class="h-sm" style="margin:0">{{ __('site.auth.change_password') }}</h2></summary>
            <form class="stack" style="gap:14px" wire:submit="changePassword">
                <label class="field">{{ __('site.auth.current_password') }}
                    <input type="password" wire:model="currentPassword" autocomplete="current-password">
                </label>
                @error('currentPassword') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.auth.new_password') }}
                    <input type="password" wire:model="newPassword" autocomplete="new-password">
                </label>
                @error('newPassword') <p class="error small">{{ $message }}</p> @enderror
                <label class="field">{{ __('site.auth.password_confirm') }}
                    <input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password">
                </label>
                <p class="muted small">{{ __('site.auth.password_rules') }} {{ __('site.auth.social_password') }}</p>
                <button class="btn" type="submit" wire:loading.attr="disabled" style="align-self:flex-start">{{ __('site.auth.change_password') }}</button>
                @if ($passwordChanged)<p class="flash small" role="status">{{ __('site.auth.password_changed') }}</p>@endif
            </form>
        </details>

        @if ($signIns)
            {{-- Facebook and Google: connect to sign in with them too, or disconnect (App\Auth\SocialSignIn; the links
                 and the DELETE are Statamic's own OAuth routes, which may first ask to confirm it's them). --}}
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
                            <a class="btn ghost" style="padding:6px 14px;font-size:14px" href="{{ $signIn->connectUrl }}">{{ __('site.auth.connect', ['provider' => $signIn->label]) }}</a>
                        @elseif ($signIn->canDisconnect)
                            <form method="POST" action="{{ $signIn->disconnectUrl }}" style="margin:0" onsubmit="return confirm(@js(__('site.auth.disconnect_confirm', ['provider' => $signIn->label])))">
                                @csrf
                                @method('DELETE')
                                <button class="btn ghost" style="padding:6px 14px;font-size:14px" type="submit">{{ __('site.auth.disconnect') }}</button>
                            </form>
                        @else
                            <span class="muted small">{{ __('site.auth.only_sign_in') }}</span>
                        @endif
                    </div>
                @endforeach
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
                    <input wire:model="payoutAccountName" maxlength="100">
                </label>
                <label class="field">{{ __('site.payouts.account_number') }}
                    <input wire:model="payoutAccountNumber" inputmode="numeric" maxlength="40">
                    @error('payoutAccountNumber') <span class="error small">{{ $message }}</span> @enderror
                </label>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <button class="btn" type="submit" wire:loading.attr="disabled">{{ __('site.payouts.save') }}</button>
                    @if ($payoutSaved)<span class="flash small" role="status" style="margin:0">{{ __('site.auth.saved') }}</span>@endif
                </div>
            </form>
        @endif
        </div>
    @endif
</div>
