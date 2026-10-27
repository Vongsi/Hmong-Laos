<?php

/*
| BCEL OnePay: customers pay an order by scanning a QR code with the BCEL One app (app/Shop/OnePay.php).
| The details come from BCEL with the merchant account. Until we have our own, .env uses BCEL's test
| merchant from their sample code; set ONEPAY_TEST=false with the real details.
*/

return [
    'merchant_id' => env('ONEPAY_MERCHANT_ID'),

    // Merchant category code, from BCEL.
    'mcc' => env('ONEPAY_MCC', '5732'),

    'shop_code' => env('ONEPAY_SHOP_CODE'),

    // City shown in the app (field 60), up to 15 letters.
    'city' => env('ONEPAY_CITY', 'VTE'),

    // BCEL's PubNub key for receiving payment messages.
    'subscribe_key' => env('ONEPAY_SUBSCRIBE_KEY'),

    // How long one QR code can be paid; the order page shows a new one after that.
    'expires_minutes' => (int) env('ONEPAY_EXPIRES_MINUTES', 15),

    // Test merchant: the order page says so and `php artisan onepay:simulate` is allowed.
    'test' => (bool) env('ONEPAY_TEST', false),

    // With the test merchant only: what the QR code asks for instead of the order total (real test
    // payments cost 1 kip), and what counts as paid in full. Ignored when ONEPAY_TEST is false.
    'test_amount' => (int) env('ONEPAY_TEST_AMOUNT', 1),
];
