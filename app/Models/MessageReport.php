<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A member reporting another from a conversation, with the latest messages as the team saw them. */
class MessageReport extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'reported_user_id', 'excerpt'];

    protected function casts(): array
    {
        return ['excerpt' => 'encrypted'];
    }
}
