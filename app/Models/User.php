<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\PlainText;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Statamic\Notifications\PasswordReset;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * Templates print names as they are, and members choose their own (sign-up, Account page, Google or
     * Facebook), so profile texts are stored without HTML whichever way the account is saved.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            foreach (['name', 'province', 'clan', 'area', 'phone', 'payout_bank', 'payout_account_name', 'payout_account_number'] as $field) {
                if (is_string($user->getAttribute($field))) {
                    $user->setAttribute($field, PlainText::line($user->getAttribute($field)));
                }
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'json',
            'super' => 'boolean',
            'last_login' => 'datetime',
            'show_in_directory' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Statamic's reset email: it links to the site's reset page (or the control panel's). Laravel's default
     * one needs a `password.reset` route this site doesn't have, so resetting a password used to fail.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordReset($token));
    }

    /**
     * The id other members' browsers receive for this member on a live (presence) channel.
     * It is different on every channel, so the id seen next to a name in a post's comment box
     * can't be used to follow an unlisted member on the site-wide "online" channel.
     */
    public function getAuthIdentifierForBroadcasting(): string
    {
        return static::presenceKey($this->getKey(), (string) request()->input('channel_name'));
    }

    /** This member's id on the site-wide "online" channel, used by the Members page for the green dots. */
    public function onlineKey(): string
    {
        return static::presenceKey($this->getKey(), 'presence-online');
    }

    public static function presenceKey(int|string $id, string $channel): string
    {
        return substr(hash_hmac('sha256', $id.'|'.$channel, config('app.key')), 0, 20);
    }
}
