<?php

use App\Search\PhoneVariants;

return [

    /*
    |--------------------------------------------------------------------------
    | Default search index
    |--------------------------------------------------------------------------
    |
    | This option controls the search index that gets queried when performing
    | search functions without explicitly selecting another index.
    |
    */

    'default' => env('STATAMIC_DEFAULT_SEARCH_INDEX', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Search Indexes
    |--------------------------------------------------------------------------
    |
    | Here you can define all of the available search indexes.
    |
    */

    'indexes' => [

        'default' => [
            'driver' => 'local',
            'searchables' => 'content',
            'fields' => ['title'],
        ],

        // The control panel's search box above the Orders and WhatsApp messages lists: by customer name, phone (as
        // people type it, App\Search\PhoneVariants), email or tracking number, not only the order number; every word
        // typed must match (App\Search\AllWordsIndex). Built on the first search and kept up to date as entries are
        // saved (App\Shop\WhatsApp updates its quiet saves itself); `php please search:update orders` /
        // `whatsapp_messages` rebuilds one.
        'orders' => [
            'driver' => 'local_all_words',
            'searchables' => 'collection:orders',
            'fields' => ['title', 'customer_name', 'customer_phone', 'customer_email', 'tracking_number'],
            'transformers' => ['customer_phone' => PhoneVariants::class],
        ],
        'whatsapp_messages' => [
            'driver' => 'local_all_words',
            'searchables' => 'collection:whatsapp_messages',
            'fields' => ['title', 'to', 'customer', 'order_number', 'sent_by'],
            'transformers' => ['to' => PhoneVariants::class],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Driver Defaults
    |--------------------------------------------------------------------------
    |
    | Here you can specify default configuration to be applied to all indexes
    | that use the corresponding driver. For instance, if you have two
    | indexes that use the "local" driver, both of them can have the
    | same base configuration. You may override for each index.
    |
    */

    'drivers' => [

        'local' => [
            'path' => storage_path('statamic/search'),
        ],

        // Local search where every word typed must match (App\Search\AllWordsIndex).
        'local_all_words' => [
            'path' => storage_path('statamic/search'),
        ],

        'algolia' => [
            'credentials' => [
                'id' => env('ALGOLIA_APP_ID', ''),
                'secret' => env('ALGOLIA_SECRET', ''),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Search Defaults
    |--------------------------------------------------------------------------
    |
    | Here you can specify default configuration to be applied to all indexes
    | regardless of the driver. You can override these per driver or per index.
    |
    */

    'defaults' => [
        'fields' => ['title'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexing Queue
    |--------------------------------------------------------------------------
    |
    | Here you may configure the queue name and connection used when indexing
    | documents, along with a timeout (in seconds) for each indexing job.
    |
    */

    'queue' => env('STATAMIC_SEARCH_QUEUE'),

    'queue_connection' => env('STATAMIC_SEARCH_QUEUE_CONNECTION'),

    'queue_timeout' => env('STATAMIC_SEARCH_QUEUE_TIMEOUT'),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Here you can configure the chunk size used when indexing documents.
    | The higher you make it, the more memory it will use, but the quicker
    | the indexing process will be.
    |
    */

    'chunk_size' => 100,

];
