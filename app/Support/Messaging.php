<?php

namespace App\Support;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Mail\TeamNotice;
use App\Models\Call;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\MemberBlock;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\User;
use App\Notifications\NewMessagePushed;
use App\Shop\Shop;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Facades\Site;
use Throwable;

/**
 * Members' private messages (the Messages page, App\Livewire\Messages): between two members, or in a group.
 *
 * Nobody gets messages from people they didn't say yes to. Between two members the first message is a request: the
 * other accepts it (or deletes it; the sender isn't told) before they can reply, and until then the sender can send
 * REQUEST_LIMIT messages. People added to a group are invited, and join (or decline) first; they don't see what was
 * written before they were added. Chats can be started with, and groups can include, members who are listed on the
 * Members page and accept new messages (My account), and people one already chats with; never across a block.
 *
 * Senders can edit their messages, or delete them for everyone, for CHANGE_HOURS. Either member of a chat can block
 * or report the other (a report also blocks, and emails the team the latest messages); anyone in a group can report
 * it. The team can stop a member from sending (the user's "Stopped from sending messages"). Text is encrypted in the
 * database; photos are private files (MessagePhoto). New messages reach the others live (Reverb, public/js/live.js)
 * and as a phone notification.
 */
class Messaging
{
    // Messages someone can send before the other member accepts their request.
    const REQUEST_LIMIT = 3;

    const PER_MINUTE = 20;

    // New conversations, and new groups, one member can start in a day.
    const NEW_PER_DAY = 20;

    const GROUPS_PER_DAY = 5;

    // Members in a group, with whoever made it.
    const GROUP_SIZE = 50;

    const NAME_LENGTH = 60;

    const MAX_LENGTH = 2000;

    // How long after sending a message its sender can edit it or delete it for everyone.
    const CHANGE_HOURS = 24;

    // How many of the latest messages a report sends the team.
    const REPORT_EXCERPT = 20;

    // Times are shown in Laos time, like the rest of the community's dates.
    const TIMEZONE = 'Asia/Vientiane';

    public static function between(int $a, int $b): ?Conversation
    {
        return Conversation::where('type', Conversation::DIRECT)->where('user_one_id', min($a, $b))->where('user_two_id', max($a, $b))->first();
    }

    public static function hasBlocked(int $blocker, int $other): bool
    {
        return MemberBlock::where('user_id', $blocker)->where('blocked_user_id', $other)->exists();
    }

    public static function blockedEitherWay(int $a, int $b): bool
    {
        return self::hasBlocked($a, $b) || self::hasBlocked($b, $a);
    }

    /** Why $from can't start a conversation with $to (a translation key), or null when they can. */
    public static function cannotStart(User $from, User $to): ?string
    {
        if ((int) $from->id === (int) $to->id) {
            return 'site.messages.cannot_message';
        }
        if ($from->messages_disabled) {
            return 'site.messages.disabled';
        }
        if (self::hasBlocked($from->id, $to->id)) {
            return 'site.messages.you_blocked';
        }
        if (self::hasBlocked($to->id, $from->id) || ! $to->accepts_messages || ! $to->show_in_directory) {
            return 'site.messages.not_accepting';
        }

        return null;
    }

    /** Whether $adder can put $user in a group: they already chat, or $user is listed and accepts new messages. */
    public static function canAdd(User $adder, User $user): bool
    {
        if ((int) $adder->id === (int) $user->id || $adder->messages_disabled || self::blockedEitherWay($adder->id, $user->id)) {
            return false;
        }

        return ($user->accepts_messages && $user->show_in_directory) || in_array((int) $user->id, self::contactIds($adder->id), true);
    }

    /** The members someone has an accepted chat with. */
    public static function contactIds(int $userId): array
    {
        return Conversation::where('type', Conversation::DIRECT)->involving($userId)
            ->whereDoesntHave('members', fn ($q) => $q->whereNull('joined_at'))
            ->get(['user_one_id', 'user_two_id'])
            ->map(fn (Conversation $c) => $c->otherId($userId))
            ->all();
    }

    /** People $user can put in a group (contacts first, then listed members who accept messages), by name. */
    public static function peopleFor(User $user, string $search, array $except, int $limit): Collection
    {
        $contacts = self::contactIds($user->id);
        $blocked = MemberBlock::where('user_id', $user->id)->pluck('blocked_user_id')
            ->merge(MemberBlock::where('blocked_user_id', $user->id)->pluck('user_id'))->all();
        $search = addcslashes(mb_substr(trim($search), 0, 50), '%_\\');

        return User::query()
            ->where(fn ($q) => $q->whereIn('id', $contacts ?: [0])->orWhere(fn ($q) => $q->where('show_in_directory', true)->where('accepts_messages', true)))
            ->whereNotIn('id', array_merge([$user->id], $except, $blocked))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderByRaw('case when id in ('.implode(',', array_map('intval', $contacts ?: [0])).') then 0 else 1 end')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'avatar']);
    }

    /** Why $from can't send in this conversation now (a translation key), or null when they can. */
    public static function cannotSend(Conversation $conversation, User $from): ?string
    {
        if ($from->messages_disabled) {
            return 'site.messages.disabled';
        }
        $members = $conversation->members()->get()->keyBy('user_id');
        $me = $members->get((int) $from->id);
        if (! $me) {
            return 'site.messages.not_member';
        }
        if ($conversation->isGroup()) {
            return $me->hasJoined() ? null : 'site.messages.accept_first';
        }

        $otherId = $conversation->otherId($from->id);
        if (self::hasBlocked($from->id, $otherId)) {
            return 'site.messages.you_blocked';
        }
        if (self::hasBlocked($otherId, $from->id)) {
            return 'site.messages.cannot_reply';
        }
        if (! $me->hasJoined()) {
            return 'site.messages.accept_first';
        }
        $other = $members->get($otherId);
        // Our request is waiting. Declined ones look the same to the sender.
        if ($other && ! $other->hasJoined() && ($other->declined_at || $conversation->messages()->where('kind', Message::TEXT)->count() >= self::REQUEST_LIMIT)) {
            return 'site.messages.wait_accept';
        }

        return null;
    }

    /**
     * Sends a message from $from to $to, starting their conversation if there isn't one yet.
     * Returns the message, or a translation key saying why it wasn't sent.
     */
    public static function send(User $from, User $to, string $body, ?UploadedFile $photo = null): Message|string
    {
        if (trim($body) === '' && ! $photo) {
            return 'site.messages.empty_message';
        }
        $conversation = self::between($from->id, $to->id);
        if (! $conversation) {
            if ($why = self::cannotStart($from, $to)) {
                return $why;
            }
            if (RateLimiter::tooManyAttempts('messages-new:'.$from->id, self::NEW_PER_DAY)) {
                return 'site.messages.too_many_new';
            }
            $conversation = self::startDirect($from, $to);
            RateLimiter::hit('messages-new:'.$from->id, 86400);
        }

        return self::post($conversation, $from, $body, $photo);
    }

    /** Sends a message in a conversation $from is in. Returns the message, or a translation key saying why not. */
    public static function post(Conversation $conversation, User $from, string $body, ?UploadedFile $photo = null): Message|string
    {
        $body = trim($body);
        if ($body === '' && ! $photo) {
            return 'site.messages.empty_message';
        }
        if (RateLimiter::tooManyAttempts('messages:'.$from->id, self::PER_MINUTE)) {
            return 'site.messages.too_fast';
        }
        if ($why = self::cannotSend($conversation, $from)) {
            return $why;
        }
        RateLimiter::hit('messages:'.$from->id, 60);

        $path = $photo ? MessagePhoto::store($photo, $conversation->id) : null;
        $message = self::record($conversation, $from, ['body' => $body !== '' ? $body : null, 'photo' => $path]);
        self::tellNew($conversation, $from);

        return $message;
    }

    private static function startDirect(User $from, User $to): Conversation
    {
        try {
            return DB::transaction(function () use ($from, $to) {
                $conversation = Conversation::create([
                    'type' => Conversation::DIRECT,
                    'user_one_id' => min($from->id, $to->id),
                    'user_two_id' => max($from->id, $to->id),
                    'started_by' => $from->id,
                ]);
                $conversation->members()->createMany([
                    ['user_id' => $from->id, 'joined_at' => now()],
                    ['user_id' => $to->id, 'invited_by' => $from->id],
                ]);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            // They both started at the same moment.
            return self::between($from->id, $to->id);
        }
    }

    /** Saves a message (text, or a system note) as the conversation's latest, read by whoever wrote it. */
    private static function record(Conversation $conversation, User $user, array $attributes): Message
    {
        return DB::transaction(function () use ($conversation, $user, $attributes) {
            $message = $conversation->messages()->create(['user_id' => $user->id] + $attributes);
            $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();
            ConversationMember::where('conversation_id', $conversation->id)->where('user_id', $user->id)->update(['last_read_id' => $message->id]);

            return $message;
        });
    }

    /** A note in a group ("Alice added Bob"), shown in each reader's language (systemText). */
    private static function system(Conversation $conversation, User $actor, string $event, array $meta = []): void
    {
        self::record($conversation, $actor, ['kind' => Message::SYSTEM, 'meta' => ['event' => $event] + $meta]);
        self::changed($conversation, $actor->id);
    }

    /**
     * The note a call leaves (App\Support\Calls): "Voice call · 3 min", or a missed call, which is unread for whoever
     * was called (a "call" message from the caller).
     */
    public static function callNote(Call $call, ?int $seconds): void
    {
        $conversation = $call->conversation;
        $caller = $call->caller;
        if (! $conversation || ! $caller) {
            return;
        }
        $missed = $seconds === null;
        self::record($conversation, $caller, [
            'kind' => $missed ? Message::CALL : Message::SYSTEM,
            'meta' => ['event' => 'call', 'video' => $call->video, 'seconds' => $seconds],
        ]);
        if ($missed) {
            self::live(new MessageSent($call->callee_id, $conversation->id));
        } else {
            self::changed($conversation, $caller->id);
        }
    }

    /**
     * The others' browsers (live) and phones (a notification, at most one every 2 minutes per conversation). In a
     * group, only members who have joined; between two members, also the one who hasn't accepted yet (the request).
     */
    private static function tellNew(Conversation $conversation, User $from): void
    {
        $recipients = $conversation->members()->with('user')->where('user_id', '!=', $from->id)
            ->when($conversation->isGroup(), fn ($q) => $q->whereNotNull('joined_at'))->get();

        foreach ($recipients as $member) {
            if (! $member->user) {
                continue;
            }
            self::live(new MessageSent($member->user_id, $conversation->id));
            $site = self::siteOf($member->user);
            $name = $from->name ?: __('site.comments.member', [], $site->lang());
            $title = match (true) {
                $conversation->isGroup() => __('site.messages.push_group', ['group' => $conversation->name, 'name' => $name], $site->lang()),
                ! $member->hasJoined() => __('site.messages.push_request', ['name' => $name], $site->lang()),
                default => __('site.messages.push_title', ['name' => $name], $site->lang()),
            };
            self::push($member->user, $conversation, $title);
        }
    }

    private static function push(User $to, Conversation $conversation, string $title): void
    {
        if (! Cache::add("messages-push:{$to->id}:{$conversation->id}", true, 120)) {
            return;
        }
        try {
            $site = self::siteOf($to);
            $to->notify(new NewMessagePushed($conversation->id, $title, __('site.messages.push_body', [], $site->lang()), self::pageUrl($to, $conversation->id)));
        } catch (Throwable $e) {
            Log::warning("Messages: phone notification for conversation {$conversation->id} not sent: {$e->getMessage()}");
        }
    }

    /** Lets the other members' open Messages pages redraw (read, edited, deleted, members or name changed). */
    private static function changed(Conversation $conversation, int $exceptUserId): void
    {
        foreach ($conversation->members()->where('user_id', '!=', $exceptUserId)->pluck('user_id') as $userId) {
            self::live(new ConversationUpdated((int) $userId, $conversation->id));
        }
    }

    private static function live(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            Log::warning("Messages: live update for conversation {$event->conversationId} not sent: {$e->getMessage()}");
        }
    }

    /** Accepts a request, or joins a group one was invited to. */
    public static function accept(Conversation $conversation, User $user): void
    {
        $updated = ConversationMember::where('conversation_id', $conversation->id)->where('user_id', $user->id)->whereNull('joined_at')
            ->update(['joined_at' => now(), 'declined_at' => null]);
        if ($updated && $conversation->isGroup()) {
            self::system($conversation, $user, 'joined');
        } elseif ($updated) {
            self::changed($conversation, $user->id);
        }
    }

    /**
     * Declining a request hides it; the one who wrote isn't told, and can't send more. Declining a group invitation
     * takes one out of the group.
     */
    public static function decline(Conversation $conversation, User $user): void
    {
        $member = ConversationMember::where('conversation_id', $conversation->id)->where('user_id', $user->id)->whereNull('joined_at')->first();
        if (! $member) {
            return;
        }
        if ($conversation->isGroup()) {
            $member->delete();
            self::changed($conversation, $user->id);

            return;
        }
        $member->forceFill(['declined_at' => now()])->save();
        self::clear($conversation, $user);
    }

    /** "Delete chat": gone for this member until a new message comes; for good once everyone has deleted it. */
    public static function clear(Conversation $conversation, User $user): void
    {
        ConversationMember::where('conversation_id', $conversation->id)->where('user_id', $user->id)
            ->update(['cleared_id' => $conversation->last_message_id, 'last_read_id' => $conversation->last_message_id]);

        // A declined request stays, so its sender can't simply start a new one.
        $members = ConversationMember::where('conversation_id', $conversation->id)->get();
        if ($members->every(fn ($m) => $m->cleared_id >= $conversation->last_message_id) && $members->whereNotNull('declined_at')->isEmpty()) {
            $conversation->delete();
        }
    }

    /** Makes a group, with $creator as its admin; the others are invited. Returns it, or why not (a translation key). */
    public static function createGroup(User $creator, string $name, array $userIds): Conversation|string
    {
        $name = PlainText::line($name);
        if ($creator->messages_disabled) {
            return 'site.messages.disabled';
        }
        if ($name === '') {
            return 'site.messages.group_name_required';
        }
        $people = User::whereIn('id', array_map('intval', $userIds))->get()->filter(fn (User $u) => self::canAdd($creator, $u))->take(self::GROUP_SIZE - 1);
        if ($people->isEmpty()) {
            return 'site.messages.group_needs_members';
        }
        if (RateLimiter::tooManyAttempts('messages-groups:'.$creator->id, self::GROUPS_PER_DAY)) {
            return 'site.messages.too_many_groups';
        }
        RateLimiter::hit('messages-groups:'.$creator->id, 86400);

        $group = DB::transaction(function () use ($creator, $name, $people) {
            $group = Conversation::create(['type' => Conversation::GROUP, 'name' => mb_substr($name, 0, self::NAME_LENGTH), 'started_by' => $creator->id]);
            $group->members()->create(['user_id' => $creator->id, 'role' => ConversationMember::ADMIN, 'joined_at' => now()]);
            foreach ($people as $person) {
                $group->members()->create(['user_id' => $person->id, 'invited_by' => $creator->id]);
            }

            return $group;
        });
        self::system($group, $creator, 'created', ['name' => $group->name]);
        self::system($group, $creator, 'added', ['users' => $people->pluck('id')->all()]);
        self::invited($group, $creator, $people);

        return $group;
    }

    /** The admin invites more people; they see what's written from now on. Returns why not, or null. */
    public static function addMembers(Conversation $group, User $admin, array $userIds): ?string
    {
        if (! self::isAdmin($group, $admin)) {
            return 'site.messages.admins_only';
        }
        $current = $group->members()->pluck('user_id')->all();
        $room = self::GROUP_SIZE - count($current);
        $people = User::whereIn('id', array_map('intval', $userIds))->whereNotIn('id', $current)->get()
            ->filter(fn (User $u) => self::canAdd($admin, $u));
        if ($people->isEmpty()) {
            return 'site.messages.group_needs_members';
        }
        if ($people->count() > $room) {
            return 'site.messages.group_full';
        }
        foreach ($people as $person) {
            $group->members()->create(['user_id' => $person->id, 'invited_by' => $admin->id, 'cleared_id' => $group->last_message_id, 'last_read_id' => $group->last_message_id]);
        }
        self::system($group, $admin, 'added', ['users' => $people->pluck('id')->all()]);
        self::invited($group, $admin, $people);

        return null;
    }

    private static function invited(Conversation $group, User $by, Collection $people): void
    {
        foreach ($people as $person) {
            self::live(new MessageSent($person->id, $group->id));
            $site = self::siteOf($person);
            self::push($person, $group, __('site.messages.push_invite', ['name' => $by->name ?: __('site.comments.member', [], $site->lang()), 'group' => $group->name], $site->lang()));
        }
    }

    public static function removeMember(Conversation $group, User $admin, int $userId): ?string
    {
        if (! self::isAdmin($group, $admin) || $userId === (int) $admin->id) {
            return 'site.messages.admins_only';
        }
        if (ConversationMember::where('conversation_id', $group->id)->where('user_id', $userId)->delete()) {
            self::system($group, $admin, 'removed', ['users' => [$userId]]);
            self::live(new ConversationUpdated($userId, $group->id));
        }

        return null;
    }

    public static function rename(Conversation $group, User $admin, string $name): ?string
    {
        $name = mb_substr(PlainText::line($name), 0, self::NAME_LENGTH);
        if (! self::isAdmin($group, $admin)) {
            return 'site.messages.admins_only';
        }
        if ($name === '') {
            return 'site.messages.group_name_required';
        }
        if ($name !== $group->name) {
            $group->forceFill(['name' => $name])->save();
            self::system($group, $admin, 'renamed', ['name' => $name]);
        }

        return null;
    }

    /** Leaving a group. If its admin leaves, whoever has been in it longest takes over; an empty group is deleted. */
    public static function leave(Conversation $group, User $user): void
    {
        $member = ConversationMember::where('conversation_id', $group->id)->where('user_id', $user->id)->first();
        if (! $group->isGroup() || ! $member) {
            return;
        }
        $member->delete();
        $joined = ConversationMember::where('conversation_id', $group->id)->whereNotNull('joined_at')->orderBy('joined_at')->orderBy('id')->get();
        if ($joined->isEmpty()) {
            $group->delete();

            return;
        }
        self::system($group, $user, 'left');
        if ($member->isAdmin() && $joined->where('role', ConversationMember::ADMIN)->isEmpty()) {
            $joined->first()->forceFill(['role' => ConversationMember::ADMIN])->save();
            self::system($group, $joined->first()->user, 'admin');
        }
    }

    /**
     * A member deleting their account: their chats with one other member go, and they leave their groups (the
     * database then removes their messages there; their photos are deleted here, as files).
     */
    public static function forgetMember(User $user): void
    {
        foreach (Conversation::involving($user->id)->get() as $conversation) {
            if (! $conversation->isGroup()) {
                $conversation->delete();

                continue;
            }
            $conversation->messages()->where('user_id', $user->id)->whereNotNull('photo')->pluck('photo')->each(fn ($path) => MessagePhoto::forget($path));
            self::leave($conversation, $user);
        }
    }

    public static function isAdmin(Conversation $conversation, User $user): bool
    {
        return $conversation->isGroup() && ConversationMember::where('conversation_id', $conversation->id)->where('user_id', $user->id)
            ->where('role', ConversationMember::ADMIN)->whereNotNull('joined_at')->exists();
    }

    /** Whether $user can still edit or delete this message for everyone (theirs, recent, not deleted). */
    public static function canChange(Message $message, User $user): bool
    {
        return $message->user_id === (int) $user->id && ! $message->isNote() && ! $message->isDeleted()
            && $message->created_at && $message->created_at->gt(now()->subHours(self::CHANGE_HOURS));
    }

    /** Changes the text of one's own message. Returns why not (a translation key), or null. */
    public static function edit(Message $message, User $user, string $body): ?string
    {
        $body = trim($body);
        if (! self::canChange($message, $user)) {
            return 'site.messages.cannot_change';
        }
        if ($user->messages_disabled) {
            return 'site.messages.disabled';
        }
        if ($body === '' && ! $message->photo) {
            return 'site.messages.empty_message';
        }
        if ($body !== (string) $message->body) {
            $message->forceFill(['body' => $body !== '' ? $body : null, 'edited_at' => now()])->save();
            self::changed($message->conversation, $user->id);
        }

        return null;
    }

    /** "Delete for everyone": the text and photo are removed; everyone sees that a message was deleted. */
    public static function unsend(Message $message, User $user): ?string
    {
        if (! self::canChange($message, $user)) {
            return 'site.messages.cannot_change';
        }
        if ($message->photo) {
            MessagePhoto::forget($message->photo);
        }
        $message->forceFill(['body' => null, 'photo' => null, 'deleted_at' => now()])->save();
        self::changed($message->conversation, $user->id);

        return null;
    }

    public static function block(User $user, int $otherId): void
    {
        MemberBlock::firstOrCreate(['user_id' => $user->id, 'blocked_user_id' => $otherId]);
    }

    public static function unblock(User $user, int $otherId): void
    {
        MemberBlock::where('user_id', $user->id)->where('blocked_user_id', $otherId)->delete();
    }

    /**
     * Reports a conversation to the team with its latest messages. In a chat between two members, the other is also
     * blocked; a group is reported as a whole.
     */
    public static function report(Conversation $conversation, User $reporter): void
    {
        $lines = $conversation->messages()->with('user')->where('kind', Message::TEXT)->latest('id')->limit(self::REPORT_EXCERPT)->get()->reverse()
            ->map(fn (Message $m) => '['.self::time($m->created_at)->format('Y-m-d H:i').'] '.($m->user?->name ?: '#'.$m->user_id).': '
                .($m->isDeleted() ? '(deleted)' : trim(($m->body ?? '').($m->photo ? ' (photo)' : '').($m->edited_at ? ' (edited)' : ''))))
            ->values()->all();
        $reported = $conversation->isGroup() ? null : $conversation->otherId($reporter->id);

        MessageReport::create([
            'conversation_id' => $conversation->id,
            'user_id' => $reporter->id,
            'reported_user_id' => $reported,
            'excerpt' => implode("\n", $lines),
        ]);
        if ($reported) {
            self::block($reporter, $reported);
        }

        $to = Shop::setting(Site::default()->handle(), 'notify_email');
        if (! $to) {
            Log::warning("Messages: no 'Send new orders to' address in Shop settings; nobody was told about a report on conversation {$conversation->id}.");

            return;
        }
        $who = fn (int $id) => (User::find($id)?->name ?? '?').' (#'.$id.')';
        try {
            Mail::to($to)->send(new TeamNotice(
                $reported ? 'Messages: a member was reported' : 'Messages: a group was reported',
                [
                    'Reported by: '.$who($reporter->id),
                    ...($reported
                        ? ['Reported member: '.$who($reported)]
                        : ['Group: '.$conversation->name, 'In it: '.$conversation->members()->pluck('user_id')->map($who)->implode(', ')]),
                    'The latest messages:',
                    ...$lines,
                    'To stop someone sending messages, open the member and switch on "Stopped from sending messages".',
                ],
                $reported ? cp_route('users.edit', $reported) : cp_route('users.index'),
                $reported ? 'Open the reported member in the control panel' : 'Open the members in the control panel',
            ));
        } catch (Throwable $e) {
            Log::error("Messages: could not email the team about a report on conversation {$conversation->id}: {$e->getMessage()}");
        }
    }

    /** Marks the conversation read up to its latest message, and lets the others' browsers show "Seen". */
    public static function markRead(Conversation $conversation, User $user): void
    {
        $updated = ConversationMember::where('conversation_id', $conversation->id)->where('user_id', $user->id)
            ->where('last_read_id', '<', $conversation->last_message_id)
            ->update(['last_read_id' => $conversation->last_message_id]);

        if ($updated) {
            self::changed($conversation, $user->id);
        }
    }

    /**
     * What this member hasn't seen yet, over all their conversations (the badge on My account): other people's
     * messages, plus group invitations they haven't opened. Notes like "Alice added Bob" don't count.
     */
    public static function unreadCount(int $userId): int
    {
        $messages = DB::table('messages')
            ->join('conversation_members as me', fn ($join) => $join->on('me.conversation_id', '=', 'messages.conversation_id')->where('me.user_id', $userId))
            ->join('conversations as c', 'c.id', '=', 'messages.conversation_id')
            ->where('messages.user_id', '!=', $userId)
            ->whereIn('messages.kind', [Message::TEXT, Message::CALL])
            ->whereNull('messages.deleted_at')
            ->whereColumn('messages.id', '>', 'me.last_read_id')
            ->whereColumn('messages.id', '>', 'me.cleared_id')
            ->where(fn ($q) => $q->whereNotNull('me.joined_at')->orWhere('c.type', Conversation::DIRECT))
            ->count();
        $invitations = DB::table('conversation_members as me')
            ->join('conversations as c', 'c.id', '=', 'me.conversation_id')
            ->where('me.user_id', $userId)->whereNull('me.joined_at')->where('c.type', Conversation::GROUP)
            ->whereColumn('me.last_read_id', '<', 'c.last_message_id')
            ->count();

        return $messages + $invitations;
    }

    /** This member's conversations, latest first, with their place in each (my_*) and unread count (`unread`). */
    public static function conversationsFor(int $userId, int $limit): Collection
    {
        return Conversation::query()
            ->join('conversation_members as me', fn ($join) => $join->on('me.conversation_id', '=', 'conversations.id')->where('me.user_id', $userId))
            ->whereColumn('conversations.last_message_id', '>', 'me.cleared_id')
            ->select('conversations.*', 'me.last_read_id as my_read_id', 'me.joined_at as my_joined_at', 'me.role as my_role')
            ->selectSub(fn ($query) => $query->from('messages')->selectRaw('count(*)')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('messages.user_id', '!=', $userId)
                ->whereIn('messages.kind', [Message::TEXT, Message::CALL])
                ->whereNull('messages.deleted_at')
                ->whereColumn('messages.id', '>', 'me.last_read_id')
                ->whereColumn('messages.id', '>', 'me.cleared_id'), 'unread')
            ->with(['userOne', 'userTwo', 'lastMessage.user', 'members'])
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id')
            ->limit($limit)
            ->get();
    }

    /** A note like "Alice added Bob and Carol", in the current language; the reader is "You". */
    public static function systemText(Message $message, int $viewerId): string
    {
        $name = function (?int $id, string $you) use ($viewerId) {
            if ($id === $viewerId) {
                return __($you);
            }

            return ($id ? User::find($id)?->name : null) ?: __('site.comments.member');
        };
        $meta = (array) $message->meta;
        $event = $meta['event'] ?? 'joined';
        if ($message->kind === Message::CALL || $event === 'call') {
            return Calls::noteText($meta, $message->user_id === $viewerId);
        }
        if ($event === 'admin' && $message->user_id === $viewerId) {
            return __('site.messages.sys_admin_you');
        }
        $users = collect($meta['users'] ?? [])->map(fn ($id) => $name((int) $id, 'site.messages.you_object'))->implode(', ');

        return __('site.messages.sys_'.$event, [
            'name' => $name($message->user_id, 'site.messages.you_name'),
            'users' => $users,
            'group' => $meta['name'] ?? '',
        ]);
    }

    /** The Messages page in this member's language (their site), optionally with a conversation open. */
    public static function pageUrl(User $user, ?int $conversationId = null): string
    {
        return route('messages.'.self::siteOf($user)->handle(), $conversationId ? ['c' => $conversationId] : []);
    }

    public static function siteOf(User $user): \Statamic\Sites\Site
    {
        return Site::get((string) $user->preferred_lang) ?? Site::default();
    }

    /**
     * A message of just one to three emojis (👍, ❤️❤️, a flag, 👨‍👩‍👧 counts as one), which the chat shows big. Skin tones,
     * the heart's colour marker and joined family emojis are part of the emoji before them.
     */
    public static function emojiOnly(?string $body): bool
    {
        $text = preg_replace('/\s+/u', '', (string) $body);
        if ($text === '' || grapheme_strlen($text) > 3) {
            return false;
        }

        return (bool) preg_match('/^(?:(?:\p{Extended_Pictographic}|\p{Regional_Indicator})[\x{FE0F}\x{200D}\x{20E3}\p{Extended_Pictographic}\p{Emoji_Modifier}\p{Regional_Indicator}\x{E0020}-\x{E007F}]*)+$/u', $text);
    }

    public static function time(CarbonInterface $at): CarbonInterface
    {
        return $at->copy()->timezone(self::TIMEZONE);
    }
}
