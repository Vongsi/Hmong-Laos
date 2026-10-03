<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Facades\Site;

class PwaController extends Controller
{
    // One manifest per language, so the installed app opens in the language it was installed from.
    public function manifest(string $site)
    {
        $site = Site::get($site) ?? Site::default();
        $locale = $site->handle() === 'hm' ? 'hmn' : $site->lang();
        $start = rtrim(parse_url($site->url(), PHP_URL_PATH) ?: '', '/').'/';

        return response()->json([
            'id' => $start,
            'name' => __('site.brand', [], $locale),
            'short_name' => __('site.brand', [], $locale),
            'description' => __('site.tagline', [], $locale),
            'lang' => $site->lang(),
            'start_url' => $start.'?source=app',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#F5F6FA',
            'theme_color' => '#1E2656',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // Saves the phone's push subscription for the signed-in member.
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|url|max:500',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'contentEncoding' => 'nullable|string|max:20',
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|url|max:500']);
        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }
}
