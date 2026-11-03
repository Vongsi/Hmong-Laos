<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One private message: text (encrypted in the database with the app key), a photo, or both. Or a system note in a
 * group ("Alice added Bob": kind system, what happened in meta). The sender can edit it (edited_at) or delete it
 * for everyone (deleted_at; the text and photo are removed) for a while (App\Support\Messaging::CHANGE_HOURS).
 */
class Message extends Model
{
    const UPDATED_AT = null;

    const TEXT = 'text';

    const SYSTEM = 'system';

    // A missed call (App\Support\Calls): a note, but unread for whoever was called.
    const CALL = 'call';

    protected $fillable = ['conversation_id', 'user_id', 'kind', 'body', 'photo', 'meta', 'edited_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'meta' => 'array',
            'conversation_id' => 'integer',
            'user_id' => 'integer',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSystem(): bool
    {
        return $this->kind === self::SYSTEM;
    }

    /** A note in the chat rather than something someone wrote: group changes and calls. */
    public function isNote(): bool
    {
        return $this->kind !== self::TEXT;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
