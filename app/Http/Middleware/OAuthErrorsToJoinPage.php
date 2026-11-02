<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function Statamic\trans as __;

/**
 * Coming back from Facebook or Google (Statamic's OAuth callback) without being signed in: back to the Join page in
 * the visitor's language rather than the home page, and "this email already has an account" in their language too
 * (join.antlers.html: sign in with email and password, then connect Facebook or Google on My account). Statamic only
 * says it in English, and only to the page it redirects to.
 */
class OAuthErrorsToJoinPage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/*/callback')) {
            return $next($request);
        }

        // The Join page's buttons pass the site's address ("/", "/lo/", "/en/") as the redirect.
        $base = (string) $request->session()->get('statamic.oauth.redirect');
        $internal = $base === '' || (str_starts_with($base, '/') && ! str_starts_with($base, '//'));
        if ($internal && ! str_starts_with($base, '/cp')) {
            config(['statamic.oauth.unauthorized_redirect' => rtrim($base, '/').'/join']);
        }

        $response = $next($request);

        // Said in the address (?signin=email_taken) rather than the session, so the Join page shows it for sure.
        if ($request->session()->get('error') === __('statamic::messages.oauth_email_exists') && $response instanceof RedirectResponse) {
            $request->session()->forget('error');
            $target = $response->getTargetUrl();
            $response->setTargetUrl($target.(str_contains($target, '?') ? '&' : '?').'signin=email_taken');
        }

        return $response;
    }
}
