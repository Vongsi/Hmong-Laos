<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A member (user_id) who blocked another (blocked_user_id): neither can message the other while it lasts. */
class MemberBlock extends Model
{
    protected $fillable = ['user_id', 'blocked_user_id'];
}
