<?php

use App\Http\Controllers\OrderReceiptController;
use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\Site;

Route::get('manifest/{site}.webmanifest', [PwaController::class, 'manifest'])->where('site', '[a-z]+');

// A customer's own payment receipt, for the history on their order page (secret link, like the page).
Route::get('order/receipt', OrderReceiptController::class)->middleware('throttle:60,1')->name('order.receipt');

// My account's full lists, one page each and in every language: /account/posts, /lo/account/products,
// /en/account/orders… (resources/views/account-list.antlers.html). My account shows the latest 5 of each.
Route::middleware('statamic.web')->group(function () {
    $titles = ['posts' => 'site.contribute.mine', 'products' => 'site.sell.mine', 'orders' => 'site.shop.my_orders', 'payouts' => 'site.payouts.mine'];
    foreach (Site::all() as $site) {
        $prefix = trim((string) parse_url($site->url(), PHP_URL_PATH), '/');
        foreach ($titles as $list => $title) {
            Route::statamic(ltrim($prefix.'/account/'.$list, '/'), 'account-list', ['list' => $list, 'title' => trans($title, [], $site->lang()), 'locale_path' => 'account/'.$list])
                ->name('account.'.$list.'.'.$site->handle());
        }
        // Privacy policy and how to have your data deleted (/privacy, /lo/delete-my-data…): their text is in
        // resources/views/partials/legal/, one file per language, so it is deployed and reviewed like code. Facebook
        // and Google ask for both addresses when the site uses their sign-in.
        foreach (['privacy' => 'privacy', 'delete-my-data' => 'data_deletion'] as $slug => $page) {
            Route::statamic(ltrim($prefix.'/'.$slug, '/'), 'legal', ['title' => trans("site.legal.{$page}", [], $site->lang()), 'body' => "legal/{$page}_".$site->lang(), 'locale_path' => $slug])
                ->name("legal.{$page}.{$site->handle()}");
        }
    }
});

Route::middleware(['auth', 'throttle:20,1'])->group(function () {
    Route::post('push/subscriptions', [PwaController::class, 'subscribe']);
    Route::delete('push/subscriptions', [PwaController::class, 'unsubscribe']);
});
