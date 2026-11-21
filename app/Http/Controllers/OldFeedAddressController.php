<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The news feed was at /xov-xwm (/lo/xov-xwm, /en/xov-xwm/{post}…) until November 2026 and is now at /feed, like the
 * other pages' English addresses. Links shared before then, in Facebook posts, WhatsApp messages and notifications,
 * are sent on to the same page at its new address, with its filters (?category=…), as a permanent move.
 */
class OldFeedAddressController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return redirect()->to(Str::replaceFirst('/xov-xwm', '/feed', $request->getPathInfo()).($query ? '?'.$query : ''), 301);
    }
}
