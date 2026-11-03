<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Conversation;
use App\Support\Calls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Calls between two members, for public/js/calls.js (the rules are in App\Support\Calls). Signed-in members only. */
class CallsController
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['conversation' => ['required', 'integer'], 'video' => ['boolean']]);
        $conversation = Conversation::involving($request->user()->id)->find($data['conversation']);
        abort_unless($conversation, 404);

        $call = Calls::start($conversation, $request->user(), (bool) ($data['video'] ?? false));
        if (is_string($call)) {
            return response()->json(['message' => __($call, ['name' => $conversation->other($request->user()->id)?->name])], 422);
        }

        return response()->json(Calls::describe($call, $request->user()) + ['ice' => Calls::iceServers($request->user())]);
    }

    /** A call the member was told about (the phone notification opens the site with ?call=). */
    public function show(Request $request, int $call): JsonResponse
    {
        $call = $this->find($request, $call);

        return response()->json(Calls::describe($call, $request->user())
            + ['ice' => $call->status === Call::RINGING && $call->callee_id === (int) $request->user()->id ? Calls::iceServers($request->user()) : []]);
    }

    public function answer(Request $request, int $call): JsonResponse
    {
        $call = $this->find($request, $call);
        if ($why = Calls::answer($call, $request->user())) {
            return response()->json(['message' => __($why)], 409);
        }

        return response()->json(['ice' => Calls::iceServers($request->user())]);
    }

    public function decline(Request $request, int $call): JsonResponse
    {
        Calls::decline($this->find($request, $call), $request->user());

        return response()->json(['ok' => true]);
    }

    /** Hanging up (also sent as the page closes, with navigator.sendBeacon). */
    public function end(Request $request, int $call): JsonResponse
    {
        Calls::end($this->find($request, $call), $request->user());

        return response()->json(['ok' => true]);
    }

    private function find(Request $request, int $id): Call
    {
        $call = Call::find($id);
        abort_unless($call && $call->involves((int) $request->user()->id), 404);

        return $call;
    }
}
