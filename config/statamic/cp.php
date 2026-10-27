<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | Whether the Control Panel should be enabled, and through what route.
    |
    */

    'enabled' => env('CP_ENABLED', true),

    'route' => env('CP_ROUTE', 'cp'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Whether the Control Panel's authentication pages should be enabled,
    | and where users should be redirected in order to authenticate.
    |
    */

    'auth' => [
        'enabled' => true,
        'redirect_to' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Start Page
    |--------------------------------------------------------------------------
    |
    | When a user logs into the Control Panel, they will be taken here.
    | For example: "dashboard", "collections/pages", etc.
    |
    */

    'start_page' => 'dashboard',

    /*
    |--------------------------------------------------------------------------
    | Dashboard Widgets
    |--------------------------------------------------------------------------
    |
    | Here you may define any number of dashboard widgets. You're free to
    | use the same widget multiple times in different configurations.
    |
    */

    'widgets' => [
        // What members sent that a moderator still has to check (app/Widgets/WaitingForReview.php).
        ['type' => 'waiting_for_review', 'width' => 100, 'can' => ['edit products entries', 'edit posts entries', 'edit events entries', 'edit interviews entries']],
        // What the shop owes members for their products, and "Prepare payout" (app/Widgets/SellerPayouts.php).
        ['type' => 'seller_payouts', 'width' => 100, 'can' => ['create seller_payouts entries']],
        // Cash received but not yet handed in to the shop, by who has it (app/Widgets/CashOnHand.php).
        ['type' => 'cash_on_hand', 'width' => 100, 'can' => ['edit payments entries']],
        // WhatsApp updates to buyers: link the shop's phone, test, latest messages (app/Widgets/WhatsAppSender.php).
        ['type' => 'whatsapp_sender', 'width' => 100, 'can' => ['edit orders entries']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Here you may define the default pagination size as well as the options
    | the user can select on any paginated listing in the Control Panel.
    |
    */

    'pagination_size' => 50,

    'pagination_size_options' => [10, 25, 50, 100],

    /*
    |--------------------------------------------------------------------------
    | Links to Documentation
    |--------------------------------------------------------------------------
    |
    | Show contextual links to documentation throughout the Control Panel.
    |
    */

    'link_to_docs' => env('STATAMIC_LINK_TO_DOCS', true),

    /*
    |--------------------------------------------------------------------------
    | Support Link
    |--------------------------------------------------------------------------
    |
    | Set the location of the support link in the header.
    |
    */

    'support_url' => env('STATAMIC_SUPPORT_URL', 'https://statamic.com/support'),

    /*
    |--------------------------------------------------------------------------
    | White Labeling
    |--------------------------------------------------------------------------
    |
    | When in Pro Mode you may replace the Statamic name, logo, favicon,
    | and add your own CSS to the control panel to match your
    | company or client's brand.
    |
    */

    'custom_cms_name' => env('STATAMIC_CUSTOM_CMS_NAME', 'Statamic'),

    'custom_logo_url' => env('STATAMIC_CUSTOM_LOGO_URL', null),

    'custom_dark_logo_url' => env('STATAMIC_CUSTOM_DARK_LOGO_URL', null),

    'custom_logo_text' => env('STATAMIC_CUSTOM_LOGO_TEXT', null),

    'custom_favicon_url' => env('STATAMIC_CUSTOM_FAVICON_URL', null),

    'custom_css_url' => env('STATAMIC_CUSTOM_CSS_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Default Timezone
    |--------------------------------------------------------------------------
    |
    | Determines the timezone used when displaying and entering dates in the
    | Control Panel. Can be overridden by individual date fields. Defaults to
    | "auto" which uses the browser's local timezone.
    |
    */

    'default_timezone' => env('STATAMIC_CP_DEFAULT_TIMEZONE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    |
    | Here you may define additional CP asset thumbnail presets.
    |
    */

    'thumbnail_presets' => [
        // 'medium' => 800,
    ],

];
