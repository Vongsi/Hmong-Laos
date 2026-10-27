<?php

namespace App\Http\Controllers;

use App\Shop\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The WhatsApp sender (whatsapp-sender/, its CALLBACK_URL) reports what happened to a message, and when: sent,
 * delivered (on the buyer's phone), read, or failed and why. It signs in with the shared token, so this route has no
 * session or CSRF (bootstrap/app.php).
 */
class WhatsAppCallbackController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('services.whatsapp_sender.token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);

        $data = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'ref' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:sent,delivered,read,failed'],
            'error' => ['nullable', 'string', 'max:500'],
            'at' => ['nullable', 'date'],
        ]);

        // The id is the message's entry in the WhatsApp messages log; "ref" (its order) is for the sender's own list.
        WhatsApp::report($data['id'], $data['status'], $data['error'] ?? null, $data['at'] ?? null);

        return response()->json(['ok' => true]);
    }
}
