<?php

namespace App\Widgets;

use App\Appearance\Background;
use App\Shop\WhatsApp;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Widgets\Widget;

/**
 * Dashboard: the WhatsApp sender for order updates to buyers (App\Shop\WhatsApp). Shows the code to link the shop's
 * phone, which number is linked, what is waiting and the latest messages; sends a test, unlinks. While the code
 * shows (it changes every half minute or so), public/vendor/app/js/cp.js reloads the content from
 * App\Http\Controllers\CP\WhatsAppController::widget().
 */
class WhatsAppSender extends Widget
{
    protected static $handle = 'whatsapp_sender';

    public function html()
    {
        return '<div data-whatsapp-sender="'.e(cp_route('whatsapp.widget')).'">'.self::content().'</div>';
    }

    public static function content(): string
    {
        $status = WhatsApp::status();

        return view('widgets.whatsapp-sender', [
            'state' => $status['status'] ?? 'offline',
            'status' => $status,
            'switchedOn' => WhatsApp::active(),
            'notice' => session('whatsapp_notice'),
            // From the log (Collections → WhatsApp messages); each opens with its text.
            'recent' => WhatsApp::latest()->map(fn (EntryContract $message) => self::row($message)),
            'attention' => WhatsApp::needsLook()->map(fn (EntryContract $message) => self::row($message)),
            'logUrl' => cp_route('collections.show', WhatsApp::COLLECTION),
        ])->render();
    }

    private static function row(EntryContract $message): object
    {
        return (object) [
            'id' => $message->id(),
            'at' => $message->date()?->copy()->setTimezone(Background::TIMEZONE)->format('j M H:i'),
            'what' => ($message->get('kind') === 'test' ? 'Test message' : trim($message->get('order_number').' · '.$message->get('about'), ' ·'))
                .($message->get('resend_of') ? ' (again)' : ''),
            'to' => '+'.$message->get('to'),
            'result' => $message->get('result'),
            'error' => $message->get('error'),
            'url' => $message->editUrl(),
        ];
    }
}
