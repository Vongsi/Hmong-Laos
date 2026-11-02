<?php

namespace App\Livewire;

use App\Auth\SocialSignIn;
use App\Auth\WhatsAppCodes;
use App\Models\User;
use App\Shop\SellerPayouts;
use App\Shop\Shop;
use App\Support\Countries;
use App\Support\ProfilePhoto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Facades\User as Members;

/**
 * The member's own account page: their details (mobile number, country, and in Laos their province, clan, district or
 * village), Members-page listing, language for notifications, and password. The mobile number, clan and district stay
 * between the member and the team (checkout fills the number in); the Members page shows only name and province (or
 * country, for members outside Laos). Only these fields
 * can be changed here; roles, email and the rest stay with the team. Members who joined with Facebook without an email
 * can add one; everyone can connect or disconnect Facebook and Google (App\Auth\SocialSignIn).
 */
class Account extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $site;

    public string $name = '';

    /** Only for members who joined with Facebook without an email: adding one. */
    public string $email = '';

    public string $phone = '';

    /** Three-letter code (App\Support\Countries); the province is only asked in Laos. */
    public string $country = '';

    public string $province = '';

    public string $clan = '';

    public string $area = '';

    public string $preferredLang = '';

    public bool $listed = false;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $saved = false;

    public bool $passwordChanged = false;

    /** The profile photo, already cropped to a square in the browser (public/js/photo-crop.js). */
    public $photo;

    public ?string $photoNotice = null;

    /** Where the team sends a seller's money (App\Shop\SellerPayouts); shown to members who sell. */
    public string $payoutBank = '';

    public string $payoutAccountName = '';

    public string $payoutAccountNumber = '';

    public bool $payoutSaved = false;

    /** What happened when they came back from connecting or disconnecting Facebook or Google. */
    #[Locked]
    public ?array $signInNotice = null;

    public function mount(): void
    {
        $this->site = Site::current()->handle();
        $this->signInNotice = self::signInNotice();

        if ($user = $this->user()) {
            $this->name = (string) $user->name;
            $this->phone = (string) $user->phone;
            $this->province = (string) $user->province;
            $this->country = (string) ($user->country ?: ($user->province ? Countries::LAOS : ''));
            $this->clan = (string) $user->clan;
            $this->area = (string) $user->area;
            $this->payoutBank = (string) $user->payout_bank;
            $this->payoutAccountName = (string) $user->payout_account_name;
            $this->payoutAccountNumber = (string) $user->payout_account_number;
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
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{6,40}$/'],
            'country' => ['nullable', Rule::in(array_keys(Countries::options($this->site)))],
            'province' => ['nullable', 'string', 'max:100'],
            'clan' => ['nullable', 'string', 'max:60'],
            'area' => ['nullable', 'string', 'max:100'],
            'preferredLang' => ['nullable', Rule::in(['hm', 'lo', 'en'])],
            // Only members without one yet (joined with Facebook, no email there) can set it here.
            'email' => SocialSignIn::isPlaceholder($user->email)
                ? ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)]
                : ['prohibited'],
        ], [
            'name.required' => __('site.shop.required'),
            '*.max' => __('site.shop.invalid'),
            '*.in' => __('site.shop.invalid'),
            'phone.regex' => __('site.shop.invalid'),
            'email.email' => __('site.shop.invalid'),
            'email.unique' => __('site.auth.email_taken'),
        ]);

        if (SocialSignIn::isPlaceholder($user->email) && trim($this->email) !== '') {
            $user->forceFill(['email' => strtolower(trim($this->email))]);
            $this->email = '';
        }

        // A province means Laos; members elsewhere have their country only.
        $this->country = $this->country ?: ($this->province ? Countries::LAOS : '');
        if ($this->country !== Countries::LAOS) {
            $this->province = '';
        }

        $user->forceFill([
            'name' => trim($this->name),
            'phone' => trim($this->phone) ?: null,
            'country' => $this->country ?: null,
            'province' => $this->province ?: null,
            'clan' => trim($this->clan) ?: null,
            'area' => trim($this->area) ?: null,
            'preferred_lang' => $this->preferredLang ?: null,
            'show_in_directory' => $this->listed,
        ])->save();

        $this->saved = true;
    }

    /** Their new profile photo (App\Support\ProfilePhoto), uploaded by the crop window. */
    public function savePhoto(): void
    {
        $user = $this->user();
        abort_unless($user, 403);
        $this->photoNotice = null;

        $this->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']], ['photo.*' => __('site.auth.photo_error')]);
        try {
            ProfilePhoto::store($user, $this->photo);
            $this->photoNotice = __('site.auth.photo_saved');
        } catch (\Throwable $e) {
            report($e);
            $this->addError('photo', __('site.auth.photo_error'));
        }
        $this->reset('photo');
    }

    public function removePhoto(): void
    {
        $user = $this->user();
        abort_unless($user, 403);
        ProfilePhoto::remove($user);
        $this->photoNotice = __('site.auth.photo_removed');
    }

    public function changePassword(): void
    {
        $user = $this->user();
        abort_unless($user, 403);
        $this->passwordChanged = false;
        $askCurrent = self::asksCurrentPassword();

        $this->validate([
            'currentPassword' => $askCurrent ? ['required', 'string'] : ['nullable'],
            'newPassword' => ['required', 'string', Password::min(8), 'same:newPasswordConfirmation'],
        ], [
            '*.required' => __('site.shop.required'),
            'newPassword.min' => __('site.auth.password_rules'),
            'newPassword.same' => __('site.auth.password_mismatch'),
        ]);

        if ($askCurrent && ! Hash::check($this->currentPassword, (string) $user->password)) {
            $this->addError('currentPassword', __('site.auth.wrong_password'));

            return;
        }

        $user->forceFill(['password' => $this->newPassword])->save();

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->passwordChanged = true;
    }

    /**
     * The current password, unless they signed in within the last 15 minutes (Statamic's "elevated session": any
     * sign-in, a WhatsApp code too). So members without one (Facebook, Google, WhatsApp) or who forgot it, and
     * have no email for a reset link, choose a new one after signing in.
     */
    public static function asksCurrentPassword(): bool
    {
        return ! request()->hasElevatedSession();
    }

    public function savePayout(): void
    {
        $user = $this->user();
        abort_unless($user, 403);
        $this->payoutSaved = false;

        $this->validate([
            'payoutBank' => ['nullable', 'string', 'max:60'],
            'payoutAccountName' => ['nullable', 'string', 'max:100'],
            'payoutAccountNumber' => ['nullable', 'string', 'max:40', 'regex:/^[0-9\-\s]{6,40}$/'],
        ], ['*.max' => __('site.shop.invalid'), 'payoutAccountNumber.regex' => __('site.shop.invalid')]);

        $user->forceFill([
            'payout_bank' => trim($this->payoutBank) ?: null,
            'payout_account_name' => trim($this->payoutAccountName) ?: null,
            'payout_account_number' => trim($this->payoutAccountNumber) ?: null,
        ])->save();

        $this->payoutSaved = true;
    }

    /**
     * Statamic says how connecting or disconnecting went, in English only (it has no Lao or Hmong): the same, in the
     * member's language.
     */
    private static function signInNotice(): ?array
    {
        $messages = ['oauth_connected' => true, 'oauth_already_connected' => true, 'oauth_disconnected' => true, 'oauth_belongs_to_another_user' => false, 'oauth_connect_unsupported' => false];
        foreach (['success', 'error'] as $flash) {
            $said = session($flash);
            if (! is_string($said) || $said === '') {
                continue;
            }
            foreach (SocialSignIn::LABELS as $label) {
                foreach ($messages as $key => $ok) {
                    if ($said === \Statamic\trans('statamic::messages.'.$key, ['provider' => $label])) {
                        return ['ok' => $ok, 'text' => __('site.auth.'.$key, ['provider' => $label])];
                    }
                }
            }
        }

        return null;
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

        $user = $this->user();
        $member = $user ? Members::find($user->id) : null;
        $connected = $member ? SocialSignIn::connected($member) : [];

        return view('livewire.account', [
            'user' => $user,
            // Joined with Facebook without an email: "Signed in with Facebook", and a field to add one.
            'noEmailVia' => SocialSignIn::placeholderProvider($user?->email),
            // Their WhatsApp sign-in number (App\Livewire\WhatsAppSignIn), once the WhatsApp sender is set up.
            'whatsappSignIn' => WhatsAppCodes::enabled(),
            // Facebook and Google (once their keys are set): connected or not, and whether it can be disconnected.
            'signIns' => collect(SocialSignIn::available())->map(fn (string $name) => (object) [
                'name' => $name,
                'label' => SocialSignIn::LABELS[$name],
                'connected' => in_array($name, $connected, true),
                'canDisconnect' => $member && SocialSignIn::canDisconnect($member, $name),
                'connectUrl' => route('statamic.oauth.login', $name).'?'.http_build_query(['redirect' => parse_url(Shop::url($this->site, 'account'), PHP_URL_PATH).'#sign-in']),
                'disconnectUrl' => route('statamic.oauth.disconnect', $name),
            ])->all(),
            'provinces' => $provinces,
            // Laos, then where many Hmong live, then every country, all in the visitor's language.
            'countries' => $countries = Countries::options($this->site),
            'commonCountries' => array_intersect_key($countries, array_flip(Countries::COMMON)),
            'inLaos' => in_array($this->country, ['', Countries::LAOS], true),
            'joinUrl' => Shop::url($this->site, 'join'),
            'membersUrl' => Shop::url($this->site, 'members'),
            // Members who sell (or were paid before) say where their money goes.
            'askCurrentPassword' => self::asksCurrentPassword(),
            'photoUrl' => ProfilePhoto::url($user?->avatar, 192),
            'isSeller' => Auth::check() && (Entry::query()->where('collection', 'products')->whereJsonContains('submitted_by', (string) Auth::id())->exists()
                || Entry::query()->where('collection', SellerPayouts::COLLECTION)->whereJsonContains('seller', (string) Auth::id())->exists()),
        ]);
    }
}
