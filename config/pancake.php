<?php

return [
    'api_key' => env('PANCAKE_API_KEY'),
    'shop_id' => env('PANCAKE_SHOP_ID'),
    'warehouse_id' => env('PANCAKE_WAREHOUSE_ID'),
    'base_url' => env('PANCAKE_BASE_URL', 'https://pos.pages.fm/api/v1'),
    'timeout' => env('PANCAKE_TIMEOUT', 30),
    'cache_ttl' => env('PANCAKE_CACHE_TTL', 600),
    'retries' => env('PANCAKE_RETRIES', 3),
];
