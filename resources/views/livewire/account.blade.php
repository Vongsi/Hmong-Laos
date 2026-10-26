<div class="join" style="max-width:560px">
    @if (! $user)
        <p class="panel">{{ __('site.auth.sign_in_first') }} <a href="{{ $joinUrl }}">{{ __('site.nav.join') }}</a></p>
    @else
        <form class="join-card" wire:submit="saveProfile">
            <p class="muted small">{{ __('site.auth.signed_in_as', ['email' => $user->email]) }}</p>
            <h2 class="h-sm">{{ __('site.auth.profile') }}</h2>
            <label class="field">{{ __('site.auth.name') }}
                <input wire:model="name" maxlength="100" autocomplete="name">
            </label>
            @error('name') <p class="error small">{{ $message }}</p> @enderror
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
            <label class="field">{{ __('site.auth.language') }}
                <select wire:model="preferredLang">
                    <option value="">—</option>
                    <option value="hm">Hmoob</option>
                    <option value="lo">ລາວ</option>
                    <option value="en">English</option>
                </select>
            </label>
            <label class="check">
                <input type="checkbox" wire:model="listed">
                <span>{{ __('site.members.listed') }}</span>
            </label>
            <p class="muted small">{{ __('site.members.listed_help') }} <a href="{{ $membersUrl }}">{{ __('site.nav.members') }}</a></p>
            <button class="btn" type="submit" wire:loading.attr="disabled" style="align-self:flex-start">{{ __('site.auth.save') }}</button>
            @if ($saved)<p class="flash small" role="status">{{ __('site.auth.saved') }}</p>@endif
        </form>

        <form class="join-card" wire:submit="changePassword">
            <h2 class="h-sm">{{ __('site.auth.change_password') }}</h2>
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
    @endif
</div>
