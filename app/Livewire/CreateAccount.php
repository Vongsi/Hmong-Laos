<?php

namespace App\Livewire;

use App\Auth\SocialSignIn;
use App\Auth\WhatsAppCodes;
use App\Models\User;
use App\Shop\Shop;
use App\Shop\WhatsApp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Events\UserRegistered;
use Statamic\Events\UserRegistering;
use Statamic\Facades\Site;
use Statamic\Facades\User as Members;

/**
 * "New here? Create an account" on the Join page: a name, an email or a mobile number, and a password. With an email
 * it is Statamic's own sign-up (the same events, roles and groups, a honeypot against bots). With a mobile number
 * we first send a 6-digit code on WhatsApp (App\Auth\WhatsAppCodes) so nobody can take someone else's number; the
 * member then signs in with that number and password, or with a code (App\Livewire\WhatsAppSignIn). They get a
 * placeholder email (App\Auth\SocialSignIn) and can add a real one on My account. Mobile numbers only once the
 * WhatsApp sender is set up.
 */
class CreateAccount extends Component
{
    // Accounts made from one network address in an hour; phones in Laos often share one through their carrier.
    const PER_ADDRESS_HOUR = 20;

    #[Locked]
    public string $site;

    /** form → code (mobile numbers only) */
    public string $step = 'form';

    public string $name = '';

    /** An email, or a mobile number when WhatsApp codes are on. */
    public string $contact = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /** Left empty by people; bots fill it in (Statamic's registration honeypot field). */
    public string $website = '';

    public string $code = '';

    /** The number the code went to, as international digits. */
    #[Locked]
    public ?string $sentTo = null;

    #[Locked]
    public int $resendAt = 0;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->site = Site::current()->handle();
    }

    public function submit(): void
    {
        $this->reset('problem');
        if ($this->website !== '') {
            return;
        }
        // Phone keyboards often add a space after a word they suggested.
        $this->contact = trim($this->contact);

        $mobile = WhatsAppCodes::enabled();
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', Password::min(8), 'same:passwordConfirmation'],
        ], [
            '*.required' => __('site.shop.required'),
            '*.max' => __('site.shop.invalid'),
            'password.min' => __('site.auth.password_rules'),
            'password.same' => __('site.auth.password_mismatch'),
        ]);

        $contact = trim($this->contact);
        if (str_contains($contact, '@') || ! $mobile) {
            $this->validate(['contact' => ['email']], ['contact.email' => __($mobile ? 'site.auth.bad_email_or_mobile' : 'site.shop.invalid')]);
            $email = strtolower($contact);
            if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
                $this->addError('contact', __('site.auth.email_taken'));

                return;
            }
            if ($this->tooMany()) {
                return;
            }
            $this->create($email);

            return;
        }

        $number = WhatsApp::number($contact);
        if (! $number || ! preg_match('/^[1-9][0-9]{7,14}$/', $number)) {
            $this->addError('contact', __('site.auth.bad_email_or_mobile'));

            return;
        }
        if (User::where('whatsapp', $number)->exists()) {
            $this->addError('contact', __('site.auth.mobile_taken'));

            return;
        }
        if ($this->tooMany()) {
            return;
        }
        $this->send($number);
    }

    public function resend(): void
    {
        $this->reset('problem');
        if ($this->sentTo) {
            $this->send($this->sentTo);
        }
    }

    /** Back to the form, to change the number or anything else. */
    public function back(): void
    {
        $this->reset('problem', 'code', 'sentTo', 'resendAt');
        $this->step = 'form';
    }

    public function verify(): void
    {
        $this->reset('problem');
        if (! $this->sentTo) {
            $this->back();

            return;
        }

        $left = null;
        $result = WhatsAppCodes::check($this->sentTo, 'join', $this->code, (string) request()->ip(), $left);
        if ($result !== 'ok') {
            $this->problem = match ($result) {
                'wrong' => trans_choice('site.auth.wa_wrong_code', $left, ['count' => $left]),
                'too_many' => __('site.auth.wa_too_many_tries'),
                default => __('site.auth.wa_code_expired'),
            };
            $this->code = '';

            return;
        }

        // Taken in the meantime (the same number twice at once).
        if (User::where('whatsapp', $this->sentTo)->exists()) {
            $this->back();
            $this->addError('contact', __('site.auth.mobile_taken'));

            return;
        }

        $this->create(SocialSignIn::placeholder('whatsapp', $this->sentTo), [
            'whatsapp' => $this->sentTo,
            'whatsapp_verified_at' => now(),
            'phone' => WhatsAppSignIn::local($this->sentTo),
        ]);
    }

    /** As Statamic's sign-up does (Statamic\Http\Controllers\User\RegisterController), then signed in. */
    private function create(string $email, array $extra = []): void
    {
        $member = Members::make()->email($email)->password($this->password);
        foreach (['name' => trim($this->name), 'preferred_lang' => $this->site] + $extra as $key => $value) {
            $member->set($key, $value);
        }
        if ($roles = config('statamic.users.new_user_roles')) {
            $member->explicitRoles($roles);
        }
        if ($groups = config('statamic.users.new_user_groups')) {
            $member->groups($groups);
        }
        if (UserRegistering::dispatch($member) === false) {
            return;
        }
        $member->save();
        UserRegistered::dispatch($member);
        RateLimiter::hit($this->limiterKey(), 3600);

        Auth::guard(config('statamic.users.guards.web', 'web'))->login($member, true);
        session()->regenerate();
        session()->elevate();

        $this->redirect(parse_url(Shop::url($this->site, '/'), PHP_URL_PATH) ?: '/');
    }

    private function send(string $to): void
    {
        $result = WhatsAppCodes::send($to, 'join', Site::get($this->site)?->lang() ?? 'en', (string) request()->ip());
        if (! $result['ok']) {
            $this->problem = match ($result['error']) {
                'wait' => __('site.auth.wa_wait', ['seconds' => $result['wait']]),
                'too_many' => __('site.auth.wa_too_many', ['minutes' => max(1, (int) ceil(($result['wait'] ?? 3600) / 60))]),
                default => __('site.auth.wa_unavailable'),
            };

            return;
        }
        $this->sentTo = $to;
        $this->resendAt = time() + WhatsAppCodes::RESEND_AFTER;
        $this->code = '';
        $this->step = 'code';
    }

    private function tooMany(): bool
    {
        if (RateLimiter::tooManyAttempts($this->limiterKey(), self::PER_ADDRESS_HOUR)) {
            $this->problem = __('site.auth.too_many', ['seconds' => RateLimiter::availableIn($this->limiterKey())]);

            return true;
        }

        return false;
    }

    private function limiterKey(): string
    {
        return 'create-account:'.request()->ip();
    }

    public function render()
    {
        return view('livewire.create-account', [
            'mobile' => WhatsAppCodes::enabled(),
            'rulesUrl' => Shop::url($this->site, 'rules'),
            'privacyUrl' => Shop::url($this->site, 'privacy'),
            'shownNumber' => $this->sentTo ? WhatsAppSignIn::local($this->sentTo) : '',
            'waitSeconds' => max(0, $this->resendAt - time()),
            'id' => 'join-'.$this->getId(),
        ]);
    }
}
