<?php

namespace App\Livewire;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use App\Shop\Shop;
use App\Support\Calls;
use App\Support\MessagePhoto;
use App\Support\Messaging;
use App\Support\ProfilePhoto;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Statamic\Facades\Site;

/**
 * The Messages page: the member's conversations (with another member, or a group) and the open one; the rules are in
 * App\Support\Messaging. On phones it shows one or the other. A panel can take the open conversation's place: a new
 * group, a group's members, adding people, renaming. public/js/live.js tells it when something arrives or changes;
 * it also checks every 30 seconds, for when the live connection is down.
 */
class Messages extends Component
{
    use WithFileUploads;

    // Messages shown at first, and added by "Earlier messages".
    const PAGE = 30;

    const PANELS = ['new-group', 'info', 'add', 'rename'];

    #[Locked]
    public string $site;

    /** The open conversation. */
    #[Url(as: 'c')]
    public ?int $open = null;

    /** A member to write to (the Members page's Message button), before they have a conversation. */
    #[Url(as: 'to')]
    public ?int $to = null;

    public string $body = '';

    public $photo = null;

    /** The message being edited (its text is in $body). */
    public ?int $editing = null;

    public int $shown = self::PAGE;

    public int $listed = 50;

    public ?string $notice = null;

    /** A panel in place of the open conversation: new-group, info, add or rename. */
    public ?string $panel = null;

    public string $groupName = '';

    /** People ticked in the picker (new group, add members). */
    public array $picked = [];

    public string $peopleSearch = '';

    public function mount(): void
    {
        $this->site = Site::current()->handle();
        $me = Auth::user();
        if ($me && $this->to) {
            $existing = Messaging::between($me->id, $this->to);
            if ($existing) {
                $this->open = $existing->id;
                $this->to = null;
            } elseif ($this->to === (int) $me->id || ! User::find($this->to)) {
                $this->to = null;
            }
        }
    }

    public function openChat(int $id): void
    {
        $this->open = $id;
        $this->to = null;
        $this->panel = null;
        $this->resetChat();
    }

    public function closeChat(): void
    {
        $this->open = null;
        $this->to = null;
        $this->panel = null;
        $this->resetChat();
    }

    public function send(): void
    {
        $me = $this->me();
        $this->validate([
            'body' => ['nullable', 'string', 'max:'.Messaging::MAX_LENGTH],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'body.max' => __('site.messages.too_long'),
            'photo.*' => __('site.messages.photo_error'),
        ]);

        if ($this->editing) {
            $message = $this->message($this->editing);
            if ($why = Messaging::edit($message, $me, $this->body)) {
                $this->addError('body', __($why, ['hours' => Messaging::CHANGE_HOURS]));

                return;
            }
            $this->reset('body', 'editing');

            return;
        }

        $conversation = $this->conversation();
        $photo = $this->photo instanceof TemporaryUploadedFile ? $this->photo : null;
        if ($conversation) {
            $result = Messaging::post($conversation, $me, $this->body, $photo);
            $other = $conversation->isGroup() ? null : $conversation->other($me->id);
        } else {
            $other = $this->to ? User::find($this->to) : null;
            abort_unless($other, 404);
            $result = Messaging::send($me, $other, $this->body, $photo);
        }
        if (is_string($result)) {
            $this->addError('body', __($result, ['name' => $this->nameOf($other)]));

            return;
        }

        $this->open = $result->conversation_id;
        $this->to = null;
        $this->reset('body', 'photo');
        $this->dispatch('chat-sent');
    }

    public function removePhoto(): void
    {
        $this->photo = null;
    }

    public function startEdit(int $id): void
    {
        $message = $this->message($id);
        abort_unless(Messaging::canChange($message, $this->me()), 403);
        $this->editing = $message->id;
        $this->body = (string) $message->body;
        $this->photo = null;
        $this->resetErrorBag();
        $this->dispatch('chat-edit');
    }

    public function cancelEdit(): void
    {
        $this->reset('body', 'editing');
        $this->resetErrorBag();
    }

    public function unsend(int $id): void
    {
        if ($why = Messaging::unsend($this->message($id), $this->me())) {
            $this->notice = __($why, ['hours' => Messaging::CHANGE_HOURS]);
        }
        if ($this->editing === $id) {
            $this->cancelEdit();
        }
    }

    public function loadEarlier(): void
    {
        $this->shown += self::PAGE;
    }

    public function moreChats(): void
    {
        $this->listed += 50;
    }

    public function accept(): void
    {
        Messaging::accept($this->conversationOrFail(), $this->me());
        $this->dispatch('chat-sent');
    }

    public function decline(): void
    {
        Messaging::decline($this->conversationOrFail(), $this->me());
        $this->closeChat();
    }

    public function block(): void
    {
        $conversation = $this->conversationOrFail();
        abort_if($conversation->isGroup(), 404);
        Messaging::block($this->me(), $conversation->otherId($this->me()->id));
    }

    public function unblock(): void
    {
        $conversation = $this->conversationOrFail();
        abort_if($conversation->isGroup(), 404);
        Messaging::unblock($this->me(), $conversation->otherId($this->me()->id));
    }

    public function report(): void
    {
        $conversation = $this->conversationOrFail();
        Messaging::report($conversation, $this->me());
        $this->notice = __($conversation->isGroup() ? 'site.messages.reported_group' : 'site.messages.reported');
    }

    public function deleteChat(): void
    {
        Messaging::clear($this->conversationOrFail(), $this->me());
        $this->closeChat();
    }

    public function leave(): void
    {
        Messaging::leave($this->conversationOrFail(), $this->me());
        $this->closeChat();
    }

    // Groups: the panels.

    public function startGroup(): void
    {
        $this->closeChat();
        $this->reset('groupName', 'picked', 'peopleSearch');
        $this->panel = 'new-group';
    }

    public function showPanel(string $panel): void
    {
        abort_unless(in_array($panel, self::PANELS, true) && $panel !== 'new-group', 404);
        $conversation = $this->conversationOrFail();
        abort_unless($conversation->isGroup(), 404);
        $this->reset('picked', 'peopleSearch');
        $this->resetErrorBag();
        $this->groupName = $panel === 'rename' ? (string) $conversation->name : '';
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->resetErrorBag();
    }

    public function unpick(int $userId): void
    {
        $this->picked = array_values(array_filter($this->picked, fn ($id) => (int) $id !== $userId));
    }

    public function createGroup(): void
    {
        $this->validate(['groupName' => ['required', 'string', 'max:'.Messaging::NAME_LENGTH], 'picked' => ['array', 'max:'.(Messaging::GROUP_SIZE - 1)]], [
            'groupName.required' => __('site.messages.group_name_required'),
            'groupName.max' => __('site.messages.group_name_long', ['max' => Messaging::NAME_LENGTH]),
            'picked.max' => __('site.messages.group_full'),
        ]);
        $result = Messaging::createGroup($this->me(), $this->groupName, $this->picked);
        if (is_string($result)) {
            $this->addError('picked', __($result, ['max' => Messaging::GROUP_SIZE]));

            return;
        }
        $this->openChat($result->id);
        $this->dispatch('chat-sent');
    }

    public function addPicked(): void
    {
        if ($why = Messaging::addMembers($this->conversationOrFail(), $this->me(), $this->picked)) {
            $this->addError('picked', __($why, ['max' => Messaging::GROUP_SIZE]));

            return;
        }
        $this->reset('picked', 'peopleSearch');
        $this->panel = 'info';
    }

    public function removeMember(int $userId): void
    {
        Messaging::removeMember($this->conversationOrFail(), $this->me(), $userId);
    }

    public function saveRename(): void
    {
        $this->validate(['groupName' => ['required', 'string', 'max:'.Messaging::NAME_LENGTH]], [
            'groupName.required' => __('site.messages.group_name_required'),
            'groupName.max' => __('site.messages.group_name_long', ['max' => Messaging::NAME_LENGTH]),
        ]);
        if ($why = Messaging::rename($this->conversationOrFail(), $this->me(), $this->groupName)) {
            $this->addError('groupName', __($why));

            return;
        }
        $this->panel = 'info';
    }

    /** From live.js: a message arrived for this member. The page redraws; the open conversation is marked read. */
    #[On('message-received')]
    public function received(?int $conversation = null): void
    {
        if ($conversation && $conversation === $this->open) {
            $this->dispatch('chat-sent');
        }
    }

    /** From live.js: something changed in a conversation (read, edited, deleted, members, name). */
    #[On('conversation-updated')]
    public function conversationUpdated(?int $conversation = null): void {}

    public function dayLabel(string $day): string
    {
        $today = now(Messaging::TIMEZONE);

        return match ($day) {
            $today->toDateString() => __('site.messages.today'),
            $today->copy()->subDay()->toDateString() => __('site.messages.yesterday'),
            default => Carbon::parse($day, Messaging::TIMEZONE)->format('j/n/Y'),
        };
    }

    public function render()
    {
        $me = Auth::user();
        if (! $me) {
            return view('livewire.messages', ['me' => null, 'joinUrl' => Shop::url($this->site, 'join')]);
        }

        $conversation = $this->conversation();
        if ($this->open && ! $conversation) {
            // Gone, or they're no longer in the group.
            $this->open = null;
            $this->panel = $this->panel === 'new-group' ? 'new-group' : null;
        }
        if ($conversation) {
            Messaging::markRead($conversation, $me);
        }
        $other = $conversation && ! $conversation->isGroup() ? $conversation->other($me->id) : (! $conversation && $this->to ? User::find($this->to) : null);
        $members = $conversation?->isGroup() ? $this->groupMembers($conversation) : collect();

        $list = Messaging::conversationsFor($me->id, $this->listed + 1);
        $chats = $list->take($this->listed)->map(fn (Conversation $c) => $this->listItem($c, $me));
        $this->dispatch('messages-unread', count: Messaging::unreadCount($me->id));

        $picking = in_array($this->panel, ['new-group', 'add'], true);
        $except = $this->panel === 'add' && $conversation ? $conversation->members->pluck('user_id')->all() : [];

        return view('livewire.messages', [
            'me' => $me,
            'requests' => $chats->where('request', true)->values(),
            'chats' => $chats->where('request', false)->values(),
            'moreChats' => $list->count() > $this->listed,
            'conversation' => $conversation,
            'isGroup' => (bool) $conversation?->isGroup(),
            'other' => $other,
            'title' => $conversation?->isGroup() ? $conversation->name : $this->nameOf($other),
            'members' => $members,
            'isAdmin' => $conversation ? Messaging::isAdmin($conversation, $me) : false,
            // Voice and video call buttons (public/js/calls.js): accepted chats between two members.
            'canCall' => $conversation && ! $conversation->isGroup() && Calls::cannotCall($conversation, $me) === null,
            'typingNames' => $conversation ? ($members->isNotEmpty() ? $members->mapWithKeys(fn ($m) => [$m->user_id => $this->nameOf($m->user)])->all() : [($other?->id ?? 0) => $this->nameOf($other)]) : [],
            ...$this->thread($conversation, $me, $other, $members),
            'people' => $picking ? Messaging::peopleFor($me, $this->peopleSearch, array_merge($except, array_map('intval', $this->picked)), 30) : collect(),
            'pickedPeople' => $picking && $this->picked ? User::whereIn('id', array_map('intval', $this->picked))->orderBy('name')->get(['id', 'name', 'avatar']) : collect(),
            'pageUrl' => route('messages.'.$this->site),
            'membersUrl' => Shop::url($this->site, 'members'),
        ]);
    }

    /** The open conversation's messages, and what the member can do there (state: null = write, or why not). */
    private function thread(?Conversation $conversation, User $me, ?User $other, Collection $members): array
    {
        $empty = ['items' => collect(), 'hasEarlier' => false, 'seen' => null, 'state' => null, 'hint' => null, 'iBlocked' => false, 'invitedBy' => null];
        if (! $conversation && ! $other) {
            return $empty;
        }
        if (! $conversation) {
            $why = Messaging::cannotStart($me, $other);

            return ['state' => $why, 'hint' => $why ? null : __('site.messages.first_is_request', ['name' => $this->nameOf($other)])] + $empty;
        }

        $mine = $conversation->memberFor($me->id);
        $messages = $conversation->messages()->with('user')->where('id', '>', (int) $mine?->cleared_id)->latest('id')->limit($this->shown + 1)->get();
        $items = $messages->take($this->shown)->reverse()->values()->map(fn (Message $m) => $m->isNote()
            ? (object) [
                'id' => $m->id,
                'system' => true,
                'text' => Messaging::systemText($m, $me->id),
                'day' => Messaging::time($m->created_at)->toDateString(),
                'call' => ($m->meta['event'] ?? null) === 'call',
                'missed' => $m->kind === Message::CALL,
                'time' => Messaging::time($m->created_at)->format('H:i'),
            ]
            : (object) [
                'id' => $m->id,
                'system' => false,
                'mine' => $m->user_id === (int) $me->id,
                'from' => $conversation->isGroup() && $m->user_id !== (int) $me->id ? $this->nameOf($m->user) : null,
                'tone' => $m->user_id % 4,
                'body' => $m->isDeleted() ? null : $m->body,
                'photo' => $m->isDeleted() ? null : MessagePhoto::url($m),
                'deleted' => $m->isDeleted(),
                'edited' => $m->edited_at !== null && ! $m->isDeleted(),
                // Just an emoji or three: shown big.
                'emoji' => ! $m->isDeleted() && ! $m->photo && Messaging::emojiOnly($m->body),
                'canChange' => Messaging::canChange($m, $me),
                'time' => Messaging::time($m->created_at)->format('H:i'),
                'day' => Messaging::time($m->created_at)->toDateString(),
            ]);

        // "Seen" (direct) or "Seen by 3" (group), under the member's latest message.
        $seen = null;
        $lastMine = $items->where('system', false)->where('mine', true)->where('deleted', false)->last();
        if ($lastMine) {
            $readers = $conversation->members->where('user_id', '!=', $me->id)->whereNotNull('joined_at')->where('last_read_id', '>=', $lastMine->id)->count();
            if ($readers) {
                $seen = ['id' => $lastMine->id, 'text' => $conversation->isGroup() ? __('site.messages.seen_by', ['count' => $readers]) : __('site.messages.seen')];
            }
        }

        $why = Messaging::cannotSend($conversation, $me);
        $hint = null;
        if (! $why && ! $conversation->isGroup() && $other && ! $conversation->memberFor($other->id)?->hasJoined()) {
            $left = Messaging::REQUEST_LIMIT - $conversation->messages()->where('kind', Message::TEXT)->count();
            $hint = __('site.messages.request_sent', ['name' => $this->nameOf($other), 'left' => $left]);
        }

        return [
            'items' => $items,
            'hasEarlier' => $messages->count() > $this->shown,
            'seen' => $seen,
            'state' => match ($why) {
                'site.messages.accept_first' => $conversation->isGroup() ? 'invite' : 'request',
                'site.messages.you_blocked' => 'blocked',
                default => $why,
            },
            'hint' => $hint,
            'iBlocked' => $other ? Messaging::hasBlocked($me->id, $other->id) : false,
            'invitedBy' => $mine?->invited_by ? $this->nameOf(User::find($mine->invited_by)) : null,
        ];
    }

    /** A group's members: admin first, then by when they joined; invited people last. */
    private function groupMembers(Conversation $conversation): Collection
    {
        return $conversation->members
            ->sortBy(fn (ConversationMember $m) => [$m->isAdmin() ? 0 : 1, $m->hasJoined() ? 0 : 1, $m->joined_at?->timestamp ?? PHP_INT_MAX, $m->id])
            ->values();
    }

    private function listItem(Conversation $c, User $me): object
    {
        $group = $c->isGroup();
        $other = $group ? null : $c->other($me->id);
        $name = $group ? (string) $c->name : $this->nameOf($other);
        $last = $c->lastMessage;
        $preview = '';
        if ($last && $last->isNote()) {
            $preview = Messaging::systemText($last, $me->id);
        } elseif ($last) {
            $text = match (true) {
                $last->isDeleted() => __('site.messages.deleted_preview'),
                $last->body !== null => Str::limit(preg_replace('/\s+/u', ' ', $last->body), 70),
                default => '📷 '.__('site.messages.photo'),
            };
            $who = match (true) {
                $last->user_id === (int) $me->id => __('site.messages.you').' ',
                $group => $this->nameOf($last->user).': ',
                default => '',
            };
            $preview = $who.$text;
        }
        $request = $c->my_joined_at === null;

        return (object) [
            'id' => $c->id,
            'group' => $group,
            'name' => $name,
            'avatar' => $group ? null : ProfilePhoto::url($other?->avatar, 96),
            'initials' => mb_strtoupper(mb_substr($name ?: '?', 0, 2)),
            'tone' => ($group ? $c->id : (int) $other?->id) % 4,
            'preview' => $preview,
            'when' => $c->last_message_at ? $this->shortTime($c->last_message_at) : '',
            'unread' => $request && $group ? (int) ($c->my_read_id < $c->last_message_id) : (int) $c->unread,
            'request' => $request,
            'pending' => ! $group && ! $request && $other && ! $c->memberFor($other->id)?->hasJoined(),
        ];
    }

    private function shortTime(CarbonInterface $at): string
    {
        $at = Messaging::time($at);
        $now = now(Messaging::TIMEZONE);

        return match (true) {
            $at->isSameDay($now) => $at->format('H:i'),
            $at->isSameYear($now) => $at->format('j/n'),
            default => $at->format('j/n/Y'),
        };
    }

    private function conversation(): ?Conversation
    {
        if (! $this->open || ! Auth::check()) {
            return null;
        }

        return Conversation::with(['members.user', 'userOne', 'userTwo'])->involving((int) Auth::id())->find($this->open);
    }

    private function conversationOrFail(): Conversation
    {
        return $this->conversation() ?? abort(404);
    }

    /** A message in the open conversation. */
    private function message(int $id): Message
    {
        return Message::with('conversation')->where('conversation_id', $this->conversationOrFail()->id)->findOrFail($id);
    }

    private function me(): User
    {
        $me = Auth::user();
        abort_unless($me, 403);

        return $me;
    }

    private function nameOf(?User $user): string
    {
        return $user?->name ?: __('site.comments.member');
    }

    private function resetChat(): void
    {
        $this->reset('body', 'photo', 'shown', 'notice', 'editing');
        $this->resetErrorBag();
    }
}
