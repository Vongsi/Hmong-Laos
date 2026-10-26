<?php

namespace App\Livewire;

use App\Models\User;
use App\Shop\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

/**
 * The member's own account page: their details, Members-page listing, language for notifications,
 * and password. Only these fields can be changed here; roles, email and the rest stay with the team.
 */
class Account extends Component
{
    #[Locked]
    public string $site;

    public string $name = '';

    public string $province = '';

    public string $preferredLang = '';

    public bool $listed = false;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $saved = false;

    public bool $passwordChanged = false;

    public function mount(): void
    {
        $this->site = Site::current()->handle();

        if ($user = $this->user()) {
            $this->name = (string) $user->name;
            $this->province = (string) $user->province;
            $this->preferredLang = (string) $user->preferred_lang;
            $this->listed = (bool) $user->show_in_directory;
        }
    }

    public function saveProfile(): void
    {
        $user = $this->user();
        abort_unless($user, 403);

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'preferredLang' => ['nullable', Rule::in(['hm', 'lo', 'en'])],
        ], [
            'name.required' => __('site.shop.required'),
            '*.max' => __('site.shop.invalid'),
            '*.in' => __('site.shop.invalid'),
        ]);

        $user->forceFill([
            'name' => trim($this->name),
            'province' => $this->province ?: null,
            'preferred_lang' => $this->preferredLang ?: null,
            'show_in_directory' => $this->listed,
        ])->save();

        $this->saved = true;
    }

    public function changePassword(): void
    {
        $user = $this->user();
        abort_unless($user, 403);
        $this->passwordChanged = false;

        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', Password::min(8), 'same:newPasswordConfirmation'],
        ], [
            '*.required' => __('site.shop.required'),
            'newPassword.min' => __('site.auth.password_rules'),
            'newPassword.same' => __('site.auth.password_mismatch'),
        ]);

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', __('site.auth.wrong_password'));

            return;
        }

        $user->forceFill(['password' => $this->newPassword])->save();

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->passwordChanged = true;
    }

    private function user(): ?User
    {
        return Auth::id() ? User::find(Auth::id()) : null;
    }

    public function render()
    {
        // Province names in the visitor's language; the saved value is the English name, as on the Members page.
        $provinces = Term::query()->where('taxonomy', 'provinces')->get()
            ->mapWithKeys(fn ($term) => [$term->in('en')?->title() ?? $term->title() => $term->in($this->site)?->title() ?? $term->title()])
            ->sort();

        return view('livewire.account', [
            'user' => $this->user(),
            'provinces' => $provinces,
            'joinUrl' => Shop::url($this->site, 'join'),
            'membersUrl' => Shop::url($this->site, 'members'),
        ]);
    }
}
