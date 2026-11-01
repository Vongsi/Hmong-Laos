<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/oauth/facebook/callback',
    ],

    // The community's Facebook Page, for "Also post to our Facebook Page" on posts, events and interviews.
    'facebook_page' => [
        'page_id' => env('FACEBOOK_PAGE_ID'),
        'token' => env('FACEBOOK_PAGE_TOKEN'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/oauth/google/callback',
    ],

    // Claude reads the payment receipts customers upload (App\Shop\Receipts). Prices are in US dollars per
    // million tokens for this model, to show what each reading cost.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'receipt_model' => env('ANTHROPIC_RECEIPT_MODEL', 'claude-haiku-5-5'),
        'input_price' => (float) env('ANTHROPIC_INPUT_PRICE', 0.10),
        'output_price' => (float) env('ANTHROPIC_OUTPUT_PRICE', 0.50),
    ],

    // Order updates to buyers from the shop's own WhatsApp number (App\Shop\WhatsApp), through the separate
    // sender in whatsapp-sender/. The token is shared with it (its TOKEN); empty = not used.
    'whatsapp_sender' => [
        'url' => env('WHATSAPP_SENDER_URL'),
        'token' => env('WHATSAPP_SENDER_TOKEN'),
        // Its linked session, queue and history; outside the release folders with zero-downtime deploys.
        'data_dir' => env('WHATSAPP_SENDER_DATA_DIR'),
        // "Continue with WhatsApp" on Join: a sign-in code sent through it (App\Auth\WhatsAppCodes).
        'sign_in' => (bool) env('WHATSAPP_SIGN_IN', true),
    ],

];
