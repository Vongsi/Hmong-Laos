<?php

namespace App\Http\Controllers;

use App\Models\ConversationMember;
use App\Models\Message;
use App\Support\MessagePhoto;
use App\Support\MessageVoice;
use App\Support\Messaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The parts of private messages outside the Messages page (App\Livewire\Messages). Signed-in members only. */
class MessagesController
{
    /** A photo from a conversation, for its two members only (and not once they've deleted the chat). */
    public function photo(Request $request, int $message): Response
    {
        $message = $this->forMember($request, $message, 'photo');
        abort_unless(Storage::disk(MessagePhoto::DISK)->exists($message->photo), 404);

        return Storage::disk(MessagePhoto::DISK)->response($message->photo, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A voice message from a conversation, for its members only (and not once they've deleted the chat). In parts,
     * as players ask (Range requests): iPhones only play sound served that way.
     */
    public function voice(Request $request, int $message): Response
    {
        $message = $this->forMember($request, $message, 'voice');
        abort_unless(Storage::disk(MessageVoice::DISK)->exists($message->voice), 404);

        return response()->file(Storage::disk(MessageVoice::DISK)->path($message->voice), [
            'Content-Type' => 'audio/mp4',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The message, when it has that file and the signed-in member can see it; otherwise "not found". */
    private function forMember(Request $request, int $id, string $file): Message
    {
        $message = Message::find($id);
        $member = $message && $request->user() ? ConversationMember::where('conversation_id', $message->conversation_id)->where('user_id', $request->user()->id)->first() : null;
        abort_unless($message?->{$file} && $member && $message->id > $member->cleared_id, 404);

        return $message;
    }

    /** The unread count for the badge on My account, when a message arrives on another page (live.js). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['count' => Messaging::unreadCount($request->user()->id)])
            ->header('Cache-Control', 'no-store');
    }
}
