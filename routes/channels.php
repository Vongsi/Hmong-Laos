<?php

use App\Models\Call;
use App\Models\ConversationMember;
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

// The two members of a private conversation (App\Support\Messaging): the "is typing" line on the Messages page.
Broadcast::channel('conversation.{id}', function ($user, int $id) {
    return ConversationMember::where('conversation_id', $id)->where('user_id', $user->id)->exists();
});

// The two members of a call while it rings or lasts (App\Support\Calls): their browsers agree on the connection here.
Broadcast::channel('call.{id}', function ($user, int $id) {
    $call = Call::find($id);

    return $call && $call->involves((int) $user->id) && $call->isOpen();
});
