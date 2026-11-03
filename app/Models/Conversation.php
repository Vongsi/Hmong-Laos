<?php

namespace App\Models;

use App\Support\MessagePhoto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Private messages (App\Support\Messaging): between two members (direct; user_one_id is the lower id) or in a named
 * group. Who is in it, and whether they've accepted, is in conversation_members.
 */
class Conversation extends Model
{
    const DIRECT = 'direct';

    const GROUP = 'group';

    protected $fillable = ['type', 'name', 'user_one_id', 'user_two_id', 'started_by', 'last_message_id', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'user_one_id' => 'integer',
            'user_two_id' => 'integer',
            'started_by' => 'integer',
            'last_message_id' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The photos are files, which the database's cascade can't remove.
        static::deleting(fn (Conversation $conversation) => MessagePhoto::forgetConversation($conversation->id));
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /** Conversations this member is in (including requests and invitations they haven't answered). */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->whereHas('members', fn ($q) => $q->where('user_id', $userId));
    }

    public function isGroup(): bool
    {
        return $this->type === self::GROUP;
    }

    /** The other member of a direct conversation. */
    public function otherId(int $userId): int
    {
        return $this->user_one_id === $userId ? (int) $this->user_two_id : (int) $this->user_one_id;
    }

    public function other(int $userId): ?User
    {
        return $this->user_one_id === $userId ? $this->userTwo : $this->userOne;
    }

    public function memberFor(int $userId): ?ConversationMember
    {
        return $this->members->firstWhere('user_id', $userId);
    }
}
