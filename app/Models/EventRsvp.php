<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventRsvp extends Model
{
    protected $fillable = ['entry_id', 'user_id', 'status', 'volunteer'];

    protected $casts = ['volunteer' => 'boolean'];
}
