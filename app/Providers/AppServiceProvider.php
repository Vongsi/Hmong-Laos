<?php

namespace App\Providers;

use App\Listeners\NotifyEntryByPush;
use App\Listeners\ShareEntryToFacebook;
use App\Services\FacebookPage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Statamic\Events\EntrySaved;

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
    }
}
