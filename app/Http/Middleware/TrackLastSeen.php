<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records when a signed-in member was last active, for the Members page and the control panel.
 * Writes at most once a minute per member.
 */
class TrackLastSeen
{
    private const EVERY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->user()?->getAuthIdentifier();

        if ($id && Cache::add('last-seen:'.$id, true, self::EVERY_SECONDS)) {
            // toBase() so this doesn't touch updated_at: being active isn't editing the profile.
            User::whereKey($id)->toBase()->update(['last_seen_at' => now()]);
        }

        return $next($request);
    }
}
