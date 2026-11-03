<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A voice or video call between the two members of a conversation (App\Support\Calls). Never recorded. */
class Call extends Model
{
    const RINGING = 'ringing';

    const ACTIVE = 'active';

    const ENDED = 'ended';

    const MISSED = 'missed';

    const DECLINED = 'declined';

    protected $fillable = ['conversation_id', 'caller_id', 'callee_id', 'video', 'status', 'answered_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'caller_id' => 'integer',
            'callee_id' => 'integer',
            'video' => 'boolean',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::RINGING, self::ACTIVE], true);
    }

    public function involves(int $userId): bool
    {
        return $this->caller_id === $userId || $this->callee_id === $userId;
    }

    public function otherId(int $userId): int
    {
        return $this->caller_id === $userId ? $this->callee_id : $this->caller_id;
    }
}
