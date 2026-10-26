<?php

use Illuminate\Support\Facades\Broadcast;
use Statamic\Facades\Entry;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Members reading the same post, event or interview. Used for the "is typing" line
// and to refresh the comment list when someone posts. Returns what others see.
Broadcast::channel('comments.{entryId}', function ($user, string $entryId) {
    if (! Entry::find($entryId)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name ?: __('site.comments.member')];
});

// Every signed-in member, on every page. Gives the live "online" count; the Members page matches
// the ids to the members who chose to be listed. Nothing here names anyone.
Broadcast::channel('online', function ($user) {
    return ['listed' => (bool) $user->show_in_directory];
});
