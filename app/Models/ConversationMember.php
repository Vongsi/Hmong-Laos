<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's place in a conversation: their role (a group's admin), whether they've accepted it (joined_at; empty
 * for a request or invitation), the last message they've seen, and up to which they don't see it (cleared_id: they
 * deleted the chat, or joined the group later).
 */
class ConversationMember extends Model
{
    const ADMIN = 'admin';

    protected $fillable = ['conversation_id', 'user_id', 'role', 'joined_at', 'declined_at', 'invited_by', 'last_read_id', 'cleared_id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'invited_by' => 'integer',
            'joined_at' => 'datetime',
            'declined_at' => 'datetime',
            'last_read_id' => 'integer',
            'cleared_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function hasJoined(): bool
    {
        return $this->joined_at !== null;
    }
}
