<?php

namespace App\Livewire;

use App\Auth\SocialSignIn;
use App\Auth\WhatsAppCodes;
use App\Models\User;
use App\Shop\Shop;
use App\Shop\WhatsApp;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Facades\Site;
use Statamic\Facades\User as Members;

/**
 * Signing in with a code sent on WhatsApp (App\Auth\WhatsAppCodes), for members without email or Facebook.
 *
 * - On Join ("join"): their number, then the code. A known number signs them in; a new one asks only for a name and
 *   makes the member (no email: a placeholder, App\Auth\SocialSignIn). Team accounts (control panel) sign in with
 *   their password instead, so a WhatsApp account alone can't open the control panel.
 * - On My account ("account"): adding, changing or removing the number they sign in with, confirmed by a code. One
 *   number, one member. Removing it is not offered when it is their only way in.
 */
class WhatsAppSignIn extends Component
{
    #[Locked]
    public string $site;

    /** join (the Join page) or account (My account → Sign-in methods) */
    #[Locked]
    public string $mode = 'join';

    /** start → number → code (→ name, for someone new) */
    public string $step = 'start';

    public string $number = '';

    public string $code = '';

    public string $name = '';

    /** The number the code went to, as international digits. */
    #[Locked]
    public ?string $sentTo = null;

    #[Locked]
    public int $resendAt = 0;

    public ?string $problem = null;

    public ?string $notice = null;

    public function mount(string $mode = 'join'): void
    {
        $this->site = Site::current()->handle();
        $this->mode = $mode === 'account' ? 'account' : 'join';
    }

    public function open(): void
    {
        $this->reset('problem', 'notice', 'code');
        $this->step = 'number';
    }

    public function changeNumber(): void
    {
        $this->open();
    }

    public function sendCode(): void
    {
        $this->reset('problem', 'notice');
        $to = WhatsApp::number($this->number);
        if (! $to || ! preg_match('/^[1-9][0-9]{7,14}$/', $to)) {
            $this->problem = __('site.auth.wa_bad_number');

            return;
        }
        if ($this->mode === 'account' && User::where('whatsapp', $to)->where('id', '!=', Auth::id())->exists()) {
            $this->problem = __('site.auth.wa_number_taken');

            return;
        }
        $this->send($to);
    }

    public function resend(): void
    {
        $this->reset('problem', 'notice');
        if ($this->sentTo) {
            $this->send($this->sentTo);
        }
    }

    public function verify(): void
    {
        $this->reset('problem', 'notice');
        if (! $this->sentTo) {
            $this->step = 'number';

            return;
        }

        $left = null;
        $result = WhatsAppCodes::check($this->sentTo, $this->purpose(), $this->code, (string) request()->ip(), $left);
        if ($result !== 'ok') {
            $this->problem = match ($result) {
                'wrong' => trans_choice('site.auth.wa_wrong_code', $left, ['count' => $left]),
                'too_many' => __('site.auth.wa_too_many_tries'),
                default => __('site.auth.wa_code_expired'),
            };
            $this->code = '';

            return;
        }

        $this->mode === 'account' ? $this->saveNumber($this->sentTo) : $this->signIn($this->sentTo);
    }

    /** Someone new, after the code: their name, then they are a member and signed in. */
    public function join(): void
    {
        $this->reset('problem');
        $verified = session('wa_sign_in');
        if (! is_array($verified) || ($verified['number'] ?? null) !== $this->sentTo || time() - ($verified['at'] ?? 0) > WhatsAppCodes::TTL) {
            $this->step = 'number';
            $this->problem = __('site.auth.wa_code_expired');

            return;
        }
        $this->validate(['name' => ['required', 'string', 'max:100']], ['name.required' => __('site.shop.required'), 'name.max' => __('site.shop.invalid')]);

        // Taken in the meantime (twice the same number at once): sign in to that member instead.
        if ($existing = User::where('whatsapp', $this->sentTo)->first()) {
            $this->logIn($existing);

            return;
        }

        $user = new User;
        $user->forceFill([
            'name' => trim($this->name),
            'email' => SocialSignIn::placeholder('whatsapp', $this->sentTo),
            'whatsapp' => $this->sentTo,
            'whatsapp_verified_at' => now(),
            'phone' => self::local($this->sentTo),
            'preferred_lang' => $this->site,
            // Listed on the Members page, like everyone who joins (they can hide themselves).
            'show_in_directory' => true,
        ])->save();
        session()->forget('wa_sign_in');

        $this->logIn($user);
    }

    /** My account: stop signing in with WhatsApp, when there is another way in. */
    public function remove(): void
    {
        $user = User::find(Auth::id());
        $member = $user ? Members::find($user->id) : null;
        if (! $member || ! SocialSignIn::canRemoveWhatsApp($member)) {
            return;
        }
        $user->forceFill(['whatsapp' => null, 'whatsapp_verified_at' => null])->save();
        $this->reset('step', 'number', 'code', 'sentTo', 'problem');
        $this->notice = __('site.auth.wa_removed');
    }

    private function send(string $to): void
    {
        $result = WhatsAppCodes::send($to, $this->purpose(), Site::get($this->site)?->lang() ?? 'en', (string) request()->ip());
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

    /** A code for signing in, or for confirming a number for this one member. */
    private function purpose(): string
    {
        return $this->mode === 'account' ? 'verify:'.Auth::id() : 'sign-in';
    }

    private function signIn(string $number): void
    {
        if ($user = User::where('whatsapp', $number)->first()) {
            $this->logIn($user);

            return;
        }
        // Someone new: remember (for 10 minutes) that they proved this number, and ask their name.
        session()->put('wa_sign_in', ['number' => $number, 'at' => time()]);
        $this->step = 'name';
    }

    private function logIn(User $user): void
    {
        $member = Members::find($user->id);
        if (! $member || $member->isSuper() || $member->hasPermission('access cp')) {
            $this->step = 'number';
            $this->problem = __('site.auth.wa_team_account');

            return;
        }
        if (method_exists($member, 'hasEnabledTwoFactorAuthentication') && $member->hasEnabledTwoFactorAuthentication()) {
            $this->step = 'number';
            $this->problem = __('site.auth.wa_team_account');

            return;
        }

        Auth::guard(config('statamic.users.guards.web', 'web'))->login($user, true);
        session()->regenerate();
        // Like signing in with Facebook or Google: they can connect another way in straight away.
        session()->elevate();

        $this->redirect(parse_url(Shop::url($this->site, '/'), PHP_URL_PATH) ?: '/');
    }

    private function saveNumber(string $number): void
    {
        $user = User::find(Auth::id());
        if (! $user) {
            return;
        }
        if (User::where('whatsapp', $number)->where('id', '!=', $user->id)->exists()) {
            $this->step = 'number';
            $this->problem = __('site.auth.wa_number_taken');

            return;
        }
        $user->forceFill(['whatsapp' => $number, 'whatsapp_verified_at' => now(), 'phone' => $user->phone ?: self::local($number)])->save();
        $this->reset('step', 'number', 'code', 'sentTo');
        $this->notice = __('site.auth.wa_saved');
    }

    /** 8562055551234 → 020 5555 1234, as people in Laos write it; other countries: +1 6515550199. */
    public static function local(string $number): string
    {
        if (preg_match('/^856(20)(\d{4})(\d{4})$/', $number, $m)) {
            return '0'.$m[1].' '.$m[2].' '.$m[3];
        }
        if (preg_match('/^856(\d{2})(\d{3})(\d{3,4})$/', $number, $m)) {
            return '0'.$m[1].' '.$m[2].' '.$m[3];
        }

        return '+'.$number;
    }

    public function render()
    {
        $user = $this->mode === 'account' && Auth::check() ? User::find(Auth::id()) : null;
        $member = $user ? Members::find($user->id) : null;

        return view('livewire.whats-app-sign-in', [
            'enabled' => WhatsAppCodes::enabled(),
            'current' => $user?->whatsapp,
            'currentShown' => $user?->whatsapp ? self::local($user->whatsapp) : null,
            'canRemove' => $member && SocialSignIn::canRemoveWhatsApp($member),
            'sentToShown' => $this->sentTo ? self::local($this->sentTo) : null,
            'waitSeconds' => max(0, $this->resendAt - time()),
            'id' => 'wa-'.$this->getId(),
        ]);
    }
}
