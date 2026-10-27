<?php

namespace App\Providers;

use App\Bard\PhotoNode;
use App\Bard\StatamicPhotoNode;
use App\Http\Controllers\CP\SellerPayoutController;
use App\Http\Controllers\CP\WhatsAppController;
use App\Listeners\NotifyEntryByPush;
use App\Listeners\SendOrderWhatsApp;
use App\Listeners\ShareEntryToFacebook;
use App\Listeners\TrackCashHandIn;
use App\Listeners\TrackMemberSubmission;
use App\Listeners\TrackOrderDelivery;
use App\Listeners\TrackOrderPayment;
use App\Listeners\TrackSellerPayout;
use App\Models\User;
use App\Search\AllWordsIndex;
use App\Services\FacebookPage;
use App\Shop\OnePay;
use App\Shop\Payments;
use App\Shop\SellerPayouts;
use App\Shop\WhatsApp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Events\FormSubmitted;
use Statamic\Events\UserRegistering;
use Statamic\Facades\Collection;
use Statamic\Facades\Search;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Fieldtypes\Bard\StatamicImageNode;
use Statamic\Statamic;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FacebookPage::class, fn () => new FacebookPage(
            config('services.facebook_page.page_id'),
            config('services.facebook_page.token'),
            config('services.facebook_page.graph_version'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(EntrySaved::class, ShareEntryToFacebook::class);
        Event::listen(EntrySaved::class, NotifyEntryByPush::class);
        Event::listen(EntrySaving::class, [TrackOrderDelivery::class, 'saving']);
        Event::listen(EntrySaved::class, [TrackOrderDelivery::class, 'saved']);
        Event::listen(EntrySaving::class, [TrackOrderPayment::class, 'saving']);
        Event::listen(EntrySaved::class, [TrackOrderPayment::class, 'saved']);
        Event::listen(EntrySaving::class, [SendOrderWhatsApp::class, 'saving']);
        Event::listen(EntrySaved::class, [SendOrderWhatsApp::class, 'saved']);
        Event::listen(EntrySaving::class, [TrackSellerPayout::class, 'saving']);
        Event::listen(EntrySaved::class, [TrackSellerPayout::class, 'saved']);
        Event::listen(EntrySaving::class, [TrackCashHandIn::class, 'saving']);
        Event::listen(EntrySaving::class, [TrackMemberSubmission::class, 'saving']);
        Event::listen(EntrySaved::class, [TrackMemberSubmission::class, 'saved']);

        // Photos in Bard text keep a size and position (app/Bard/PhotoLayout.php). In the control panel,
        // public/vendor/app/js/cp.js and css/cp.css show them and add buttons to change them.
        Augmentor::replaceExtension('image', fn ($image) => $image instanceof StatamicImageNode ? new StatamicPhotoNode : new PhotoNode);
        // An order's payment history for the team, read only (App\Shop\Payments): never saved from the control panel,
        // so a save there can't drop what the customer added meanwhile.
        Collection::computed('orders', 'payment_log', fn ($order) => Payments::historyText($order));
        Collection::computed('orders', 'receipts', fn ($order) => Payments::receipts($order) ?: null);
        // Its WhatsApp updates to the buyer, from the WhatsApp messages log (App\Shop\WhatsApp), and the dashboard's
        // "WhatsApp updates to buyers".
        Collection::computed('orders', 'whatsapp_messages', fn ($order) => WhatsApp::messages($order)->map->id()->all() ?: null);
        // The search box above the Orders and WhatsApp messages lists: every word typed must match (App\Search).
        Search::extend('local_all_words', fn ($app, array $config, string $name, ?string $locale) => new AllWordsIndex($name, $config, $locale));
        Statamic::pushCpRoutes(function () {
            Route::get('whatsapp/widget', [WhatsAppController::class, 'widget'])->name('whatsapp.widget');
            Route::post('whatsapp/test', [WhatsAppController::class, 'test'])->name('whatsapp.test');
            Route::post('whatsapp/resend/{id}', [WhatsAppController::class, 'resend'])->name('whatsapp.resend');
            Route::post('whatsapp/unlink', [WhatsAppController::class, 'unlink'])->name('whatsapp.unlink');
        });

        // Seller payouts (App\Shop\SellerPayouts): where to send the money, and "Prepare payout" on the dashboard.
        Collection::computed(SellerPayouts::COLLECTION, 'pay_to', fn ($payout) => SellerPayouts::payTo(User::find(((array) $payout->get('seller'))[0] ?? null)) ?? 'Not given yet: ask the member to add it on My account.');
        Statamic::pushCpRoutes(fn () => Route::post('seller-payouts/prepare', [SellerPayoutController::class, 'prepare'])->name('seller-payouts.prepare'));

        Statamic::script('app', 'cp.js?v=6');
        Statamic::style('app', 'cp.css?v=3');

        // Site URLs are relative ("/", "/lo/", "/en/"), so links use the address the visitor came in on.
        // Behind a proxy (Cloudflare, Nginx) that would be http://, so follow APP_URL's https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // `composer dev` / `php artisan dev` (scripts/dev.ps1 on Windows): the web server, Reverb and the
        // scheduler together. --no-reload passes the shell's environment on to the web server (OPENSSL_CONF
        // for phone notifications, the -Lan addresses). No queue worker: jobs run straight away
        // (QUEUE_CONNECTION=sync). No Vite: the site's CSS and JS are plain files in public/.
        if ($this->app->runningInConsole()) {
            DevCommands::except('server', 'queue', 'vite');
            DevCommands::artisan('serve --no-reload', 'web')->blue();
            DevCommands::artisan('reverb:start', 'reverb')->green();
            DevCommands::artisan('schedule:work', 'scheduler')->purple();
            // BCEL One payments, once the OnePay details are in .env.
            if (OnePay::configured()) {
                DevCommands::artisan('onepay:listen', 'onepay')->yellow();
            }
            // WhatsApp updates to buyers, once WHATSAPP_SENDER_* are in .env and whatsapp-sender/ is installed.
            if (WhatsApp::configured() && is_dir(base_path('whatsapp-sender/node_modules'))) {
                DevCommands::artisan('whatsapp:sender', 'whatsapp')->pink();
            }
        }

        // Carbon has no Hmong, so "5 minutes ago" would stay English on the Hmong site.
        Carbon::getTranslator()->setMessages('hmn', require lang_path('hmn/time.php'));

        // Statamic limits its sign-in, sign-up and password forms (and the control panel's sign-in) with the
        // "statamic.auth" limiter: 4 a minute per network address, answered with a bare 429 page. Many phones
        // in Laos share one address through their carrier, so allow 30 a minute per address, limit sign-ins
        // and reset emails per email address instead, and send people back to the form with a message.
        RateLimiter::for('statamic.auth', function (Request $request) {
            $tooMany = function (Request $request, array $headers) {
                $message = __('site.auth.too_many', ['seconds' => $headers['Retry-After'] ?? 60]);
                $bag = match (true) {
                    $request->is('!/auth/register') => 'user.register',
                    $request->is('!/auth/password/email') => 'user.forgot_password',
                    $request->is('!/auth/password') => 'user.password',
                    $request->is('!/auth/profile') => 'user.profile',
                    default => 'default',
                };

                return $request->expectsJson()
                    ? response()->json(['message' => $message], 429, $headers)
                    : back()->withInput($request->except('password', 'password_confirmation'))->withErrors(['email' => $message], $bag);
            };

            $limits = [Limit::perMinute(30)->by('auth-ip:'.$request->ip())->response($tooMany)];
            $email = Str::lower(trim((string) $request->input('email')));
            if ($email !== '' && $request->is('!/auth/login', '!/auth/password/email', 'cp/auth/login', 'cp/auth/password/email')) {
                $limits[] = Limit::perMinute(5)->by('auth-email:'.sha1($email))->response($tooMany);
            }

            return $limits;
        });

        // Sign-up accepts any profile field a form sends; new members always start unlisted on the
        // Members page and "never seen". They change the first themselves on their Account page.
        Event::listen(UserRegistering::class, function (UserRegistering $event) {
            $event->user->set('show_in_directory', false);
            $event->user->set('last_seen_at', null);
        });

        // Only signed-in members may send a product. The form is hidden from visitors; this also
        // stops direct posts, and Statamic deletes any uploaded photos when the submission is refused.
        Event::listen(FormSubmitted::class, function (FormSubmitted $event) {
            if ($event->submission->form()->handle() === 'suggest_product' && ! Auth::check()) {
                throw ValidationException::withMessages(['product' => __('site.shop.sell_join')]);
            }
        });
    }
}
