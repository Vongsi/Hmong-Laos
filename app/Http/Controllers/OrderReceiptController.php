<?php

namespace App\Http\Controllers;

use App\Shop\Payments;
use App\Shop\Receipts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Entry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A receipt the customer added, for the payment history on their order page. Receipts are private, so they
 * are only shown with the same secret as the page (?n=order number&t=token), and only the order's own.
 */
class OrderReceiptController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $order = Entry::query()->where('collection', 'orders')->where('title', (string) $request->query('n'))->first();
        abort_unless($order && hash_equals((string) $order->get('token'), (string) $request->query('t')), 404);

        $file = (string) $request->query('f');
        $path = collect(Payments::receipts($order))->first(fn (string $path) => basename($path) === $file);
        $disk = Storage::disk(Receipts::CONTAINER);
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ], 'inline');
    }
}
