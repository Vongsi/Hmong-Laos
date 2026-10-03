<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushBroadcast extends Model
{
    protected $fillable = ['entry_id', 'recipients'];
}
