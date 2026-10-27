<?php

namespace App\Http\Controllers\CP;

use App\Shop\WhatsApp;
use App\Widgets\WhatsAppSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The dashboard's "WhatsApp updates" (App\Widgets\WhatsAppSender): its content again while the code to scan changes
 * (public/vendor/app/js/cp.js), a test message, resending a message that needs a look, and unlinking the shop's
 * number. For whoever can edit orders.
 */
class WhatsAppController
{
    public function widget(): Response
    {
        self::authorize();

        return response(WhatsAppSender::content())->header('Cache-Control', 'no-store');
    }

    public function test(Request $request): RedirectResponse
    {
        self::authorize();
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $error = WhatsApp::test($data['phone'], 'Test from '.config('app.name').' ('.User::current()->name().'): WhatsApp updates work.', User::current()->name());

        return self::back($error, 'Test message queued: it should arrive within a minute.');
    }

    /** "Resend" for a message the dashboard lists under "Needs a look". */
    public function resend(string $id): RedirectResponse
    {
        self::authorize();
        $message = Entry::find($id);
        abort_unless($message?->collectionHandle() === WhatsApp::COLLECTION, 404);
        $error = WhatsApp::resend($message, User::current()->name());

        return self::back($error ? 'Not sent again: '.$error : null, 'Sent again: queued, it should arrive within a minute.');
    }

    public function unlink(): RedirectResponse
    {
        self::authorize();
        $error = WhatsApp::unlink();

        return self::back($error ? 'Could not unlink: '.$error : null, 'Unlinked. Scan the new code to link a phone again.');
    }

    /** To the dashboard, with the outcome as a toast and in the box. */
    private static function back(?string $error, string $done): RedirectResponse
    {
        return redirect(cp_route('dashboard'))
            ->with($error ? 'error' : 'success', $error ?? $done)
            ->with('whatsapp_notice', ['ok' => ! $error, 'text' => $error ?? $done]);
    }

    private static function authorize(): void
    {
        abort_unless(User::current()?->can('edit orders entries'), 403);
    }
}
