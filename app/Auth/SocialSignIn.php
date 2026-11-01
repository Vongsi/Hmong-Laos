<?php

namespace App\Auth;

use App\Models\User as UserModel;
use Closure;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\OAuth;
use Statamic\Facades\User;
use Statamic\OAuth\Provider;

/**
 * Joining and signing in with Facebook or Google (Statamic OAuth; the buttons on Join show once FACEBOOK_* /
 * GOOGLE_* are set in .env).
 *
 * - Many people in Laos have a Facebook account with only a phone number, so Facebook may send no email. The site
 *   needs one for each member, so they get a placeholder that can never receive mail (facebook-<id>@no-email.invalid,
 *   RFC 2606): mail to it is dropped (App\Listeners\SkipPlaceholderEmails), it is never shown, and My account asks
 *   for a real one.
 * - A member can connect or disconnect Facebook and Google on My account (Statamic connects a provider when the
 *   member is signed in; App\Livewire\Account). One who joined with email and password and then taps the Facebook
 *   button is told to sign in and connect it there (App\Http\Middleware\OAuthErrorsToJoinPage).
 */
class SocialSignIn
{
    const PLACEHOLDER_DOMAIN = 'no-email.invalid';

    const LABELS = ['facebook' => 'Facebook', 'google' => 'Google'];

    /** Who a placeholder email came from: Facebook, or joining with a WhatsApp code (App\Livewire\WhatsAppSignIn). */
    const PLACEHOLDER_LABELS = self::LABELS + ['whatsapp' => 'WhatsApp'];

    /** New members from Facebook or Google: their name, and their email or a placeholder. */
    public static function register(): void
    {
        // OAuth::provider() keeps the one each sign-in uses; OAuth::providers() makes new ones every time.
        foreach (OAuth::providers()->keys() as $name) {
            OAuth::provider($name)?->withUser(fn ($socialite) => User::make()
                ->email($socialite->getEmail() ?: self::placeholder($name, (string) $socialite->getId()))
                ->data([
                    'name' => $socialite->getName() ?: $socialite->getNickname() ?: self::LABELS[$name] ?? $name,
                    // Listed on the Members page, like everyone who joins (they can hide themselves).
                    'show_in_directory' => true,
                ]));
        }
    }

    public static function placeholder(string $provider, string $id): string
    {
        return $provider.'-'.preg_replace('/[^A-Za-z0-9_-]/', '', $id).'@'.self::PLACEHOLDER_DOMAIN;
    }

    public static function isPlaceholder(?string $email): bool
    {
        return str_ends_with(strtolower((string) $email), '@'.self::PLACEHOLDER_DOMAIN);
    }

    /** "Facebook" for a member who joined with Facebook and has no email yet. */
    public static function placeholderProvider(?string $email): ?string
    {
        return self::isPlaceholder($email) ? self::PLACEHOLDER_LABELS[strtok((string) $email, '-')] ?? null : null;
    }

    /** The providers set up in .env (their keys filled in), in button order. */
    public static function available(): array
    {
        return array_values(array_filter(array_keys(self::LABELS), fn (string $name) => filled(config("services.{$name}.client_id")) && OAuth::provider($name)));
    }

    /** Which of them this member has connected. */
    public static function connected(StatamicUser $user): array
    {
        return array_values(array_filter(self::available(), fn (string $name) => isset(self::ids(OAuth::provider($name))[$user->id()])));
    }

    /**
     * Whether disconnecting $name would still leave a way in: another provider, their WhatsApp number, or an email they
     * can set a password with ("Forgot your password?").
     */
    public static function canDisconnect(StatamicUser $user, string $name): bool
    {
        return ! self::isPlaceholder($user->email()) || count(array_diff(self::connected($user), [$name])) > 0 || self::hasWhatsApp($user);
    }

    /** Whether removing their WhatsApp number would still leave a way in: an email, or Facebook or Google. */
    public static function canRemoveWhatsApp(StatamicUser $user): bool
    {
        return ! self::isPlaceholder($user->email()) || self::connected($user) !== [];
    }

    public static function hasWhatsApp(StatamicUser $user): bool
    {
        return filled(UserModel::find($user->id())?->whatsapp);
    }

    /** Statamic keeps who is connected to what in storage/statamic/oauth/<provider>.php: read it its own way. */
    private static function ids(Provider $provider): array
    {
        return (array) Closure::bind(fn () => $this->getIds(), $provider, $provider::class)();
    }
}
