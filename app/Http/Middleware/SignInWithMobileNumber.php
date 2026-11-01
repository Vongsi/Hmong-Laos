<?php

namespace App\Http\Middleware;

use App\Auth\SocialSignIn;
use App\Models\User;
use App\Shop\WhatsApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign in on the Join page takes an email or a mobile number (join.antlers.html). Statamic signs in by email, so a
 * mobile number is swapped for the email of the member whose confirmed WhatsApp number it is (users.whatsapp;
 * App\Livewire\CreateAccount, App\Livewire\WhatsAppSignIn) before Statamic's sign-in sees it. A number nobody has
 * becomes an address nobody has, so the answer is the usual "these details don't match", never whether the number
 * has an account.
 */
class SignInWithMobileNumber
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('post') && $request->is('!/auth/login')) {
            $given = trim((string) $request->input('email'));
            if (preg_match('/^\+?[0-9()\-\s]{6,24}$/', $given) && ($number = WhatsApp::number($given))) {
                $email = User::where('whatsapp', $number)->value('email');
                $request->merge(['email' => $email ?? 'unknown-'.$number.'@'.SocialSignIn::PLACEHOLDER_DOMAIN]);
            }
        }

        return $next($request);
    }
}
