<?php

namespace App\Support;

use App\Events\CallSignal;
use App\Models\Call;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\IncomingCallPushed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Voice and video calls between the two members of a chat (the call buttons on the Messages page; public/js/calls.js).
 *
 * The call itself goes straight between the two browsers (WebRTC, always encrypted). This sets it up: it rings the
 * other member on whatever page of the site they have open (CallSignal on their own channel) and on their phone
 * (a notification), and keeps track of how it went. The browsers then agree on the connection over the call's
 * private channel (routes/channels.php). Networks that can't connect directly go through our relay (coturn,
 * deploy/docker-compose.yml), still encrypted; iceServers() gives each member a login for it that expires.
 *
 * Only members whose chat is accepted can call each other, never across a block, and not if the team stopped them
 * sending messages. Nothing is recorded: a call leaves only a note in the chat ("Voice call · 3 min", or a missed
 * call, which is unread for whoever was called).
 */
class Calls
{
    // How long a call rings before it counts as missed (public/js/calls.js hangs up then too).
    const RING_SECONDS = 45;

    // Calls one member can start in a minute.
    const PER_MINUTE = 6;

    // A call still open after this long was left behind (a closed laptop): it's ended.
    const LONGEST_HOURS = 6;

    // How long a relay login works, in seconds.
    const RELAY_LOGIN = 6 * 3600;

    /** Why $from can't call in this conversation (a translation key), or null when they can. */
    public static function cannotCall(Conversation $conversation, User $from): ?string
    {
        if ($conversation->isGroup()) {
            return 'site.calls.cannot_call';
        }
        if ($from->messages_disabled) {
            return 'site.messages.disabled';
        }
        $members = $conversation->members()->get();
        if ($members->count() !== 2 || ! $members->contains('user_id', (int) $from->id) || $members->contains(fn ($m) => ! $m->hasJoined())) {
            return 'site.calls.cannot_call';
        }
        if (Messaging::blockedEitherWay($from->id, $conversation->otherId($from->id))) {
            return 'site.calls.cannot_call';
        }

        return null;
    }

    /** Rings the other member. Returns the call, or why not (a translation key). */
    public static function start(Conversation $conversation, User $caller, bool $video): Call|string
    {
        self::expireStale();
        if ($why = self::cannotCall($conversation, $caller)) {
            return $why;
        }
        if (RateLimiter::tooManyAttempts('calls:'.$caller->id, self::PER_MINUTE)) {
            return 'site.calls.too_many';
        }
        RateLimiter::hit('calls:'.$caller->id, 60);

        // A call this member left open somewhere (another tab) ends now.
        foreach (self::openCalls($caller->id) as $old) {
            self::end($old, $caller);
        }
        $calleeId = $conversation->otherId($caller->id);
        if (self::openCalls($calleeId)->isNotEmpty()) {
            return 'site.calls.busy';
        }

        $call = Call::create([
            'conversation_id' => $conversation->id,
            'caller_id' => $caller->id,
            'callee_id' => $calleeId,
            'video' => $video,
            'status' => Call::RINGING,
        ]);
        self::signal($calleeId, $call, 'ring');

        $callee = User::find($calleeId);
        if ($callee) {
            try {
                $site = Messaging::siteOf($callee);
                $name = $caller->name ?: __('site.comments.member', [], $site->lang());
                $callee->notify(new IncomingCallPushed(
                    $call->id,
                    __($video ? 'site.calls.push_video' : 'site.calls.push_voice', ['name' => $name], $site->lang()),
                    __('site.calls.push_body', [], $site->lang()),
                    route('messages.'.$site->handle(), ['c' => $conversation->id, 'call' => $call->id]),
                ));
            } catch (Throwable $e) {
                Log::warning("Calls: phone notification for call {$call->id} not sent: {$e->getMessage()}");
            }
        }

        return $call;
    }

    /** The member who was called answers. Returns why not (a translation key), or null. */
    public static function answer(Call $call, User $user): ?string
    {
        if ($call->callee_id !== (int) $user->id || $call->status !== Call::RINGING) {
            return 'site.calls.gone';
        }
        $call->forceFill(['status' => Call::ACTIVE, 'answered_at' => now()])->save();
        self::signal($call->caller_id, $call, 'answered');
        // Their other tabs and devices stop ringing.
        self::signal($call->callee_id, $call, 'answered');

        return null;
    }

    public static function decline(Call $call, User $user): void
    {
        if ($call->callee_id === (int) $user->id && $call->status === Call::RINGING) {
            self::finish($call, Call::DECLINED);
        }
    }

    /** Either of them hangs up: a call that was never answered is missed. */
    public static function end(Call $call, User $user): void
    {
        if ($call->involves((int) $user->id) && $call->isOpen()) {
            self::finish($call, $call->status === Call::RINGING ? Call::MISSED : Call::ENDED);
        }
    }

    /** Calls nobody answered, and calls left open (a browser closed mid-call). Also every minute (routes/console.php). */
    public static function expireStale(): void
    {
        $stale = Call::where(fn ($q) => $q->where('status', Call::RINGING)->where('created_at', '<', now()->subSeconds(self::RING_SECONDS + 15)))
            ->orWhere(fn ($q) => $q->where('status', Call::ACTIVE)->where('answered_at', '<', now()->subHours(self::LONGEST_HOURS)))
            ->get();
        foreach ($stale as $call) {
            self::finish($call, $call->status === Call::RINGING ? Call::MISSED : Call::ENDED);
        }
    }

    private static function finish(Call $call, string $status): void
    {
        $call->forceFill(['status' => $status, 'ended_at' => now()])->save();
        $seconds = $status === Call::ENDED && $call->answered_at ? (int) $call->answered_at->diffInSeconds($call->ended_at, true) : null;
        Messaging::callNote($call, $seconds);
        $reason = $status === Call::DECLINED ? 'declined' : ($status === Call::MISSED ? 'missed' : 'ended');
        self::signal($call->caller_id, $call, 'ended', $reason);
        self::signal($call->callee_id, $call, 'ended', $reason);
    }

    public static function openCalls(int $userId)
    {
        return Call::whereIn('status', [Call::RINGING, Call::ACTIVE])
            ->where(fn ($q) => $q->where('caller_id', $userId)->orWhere('callee_id', $userId))
            ->get();
    }

    /** What a member's browser needs to know about a call (public/js/calls.js). */
    public static function describe(Call $call, User $for): array
    {
        $other = User::find($call->otherId((int) $for->id));
        $name = $other?->name ?: __('site.comments.member');

        return [
            'id' => $call->id,
            'conversation' => $call->conversation_id,
            'video' => $call->video,
            'status' => $call->status,
            'incoming' => $call->callee_id === (int) $for->id,
            'name' => $name,
            'initials' => mb_strtoupper(mb_substr($name, 0, 2)),
            'avatar' => ProfilePhoto::url($other?->avatar, 192),
        ];
    }

    /**
     * Where browsers can find each other: our relay (coturn), with a login that only works for a while and only for
     * this member (its "use-auth-secret" mode). Without a relay set up (TURN_HOST, TURN_SECRET), none: browsers on
     * the same network still connect.
     */
    public static function iceServers(User $user): array
    {
        $host = config('services.turn.host');
        $secret = config('services.turn.secret');
        if (! $host || ! $secret) {
            return [];
        }
        // The site's own address, without a port (the relay has its own).
        $host = preg_replace('/:\d+$/', '', (string) $host);
        $port = (int) config('services.turn.port', 3478);
        $username = (time() + self::RELAY_LOGIN).':'.$user->id;

        return [
            ['urls' => "stun:{$host}:{$port}"],
            [
                'urls' => ["turn:{$host}:{$port}?transport=udp", "turn:{$host}:{$port}?transport=tcp"],
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            ],
        ];
    }

    /** "Voice call · 3 min", or a missed call (as the one who called: "no answer"). */
    public static function noteText(array $meta, bool $viewerCalled): string
    {
        $video = (bool) ($meta['video'] ?? false);
        $seconds = $meta['seconds'] ?? null;
        if ($seconds === null) {
            return __(match (true) {
                $viewerCalled => $video ? 'site.calls.note_video_no_answer' : 'site.calls.note_voice_no_answer',
                default => $video ? 'site.calls.note_video_missed' : 'site.calls.note_voice_missed',
            });
        }
        $length = $seconds < 60 ? __('site.calls.seconds', ['count' => (int) $seconds]) : __('site.calls.minutes', ['count' => (int) round($seconds / 60)]);

        return __($video ? 'site.calls.note_video' : 'site.calls.note_voice', ['length' => $length]);
    }

    private static function signal(int $userId, Call $call, string $action, ?string $reason = null): void
    {
        try {
            broadcast(new CallSignal($userId, $action, self::describe($call, User::find($userId) ?? new User) + ['reason' => $reason]));
        } catch (Throwable $e) {
            Log::warning("Calls: live update for call {$call->id} not sent: {$e->getMessage()}");
        }
    }
}
