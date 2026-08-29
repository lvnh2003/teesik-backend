<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'ghn' => [
        'token' => env('GHN_API_TOKEN'),
        'shop_id' => env('GHN_SHOP_ID'),
    ],

    'momo' => [
        'env' => env('MOMO_ENV', 'sandbox'),
        'partner_code' => env('MOMO_PARTNER_CODE'),
        'access_key' => env('MOMO_ACCESS_KEY'),
        'secret_key' => env('MOMO_SECRET_KEY'),
        'endpoint' => env('MOMO_ENDPOINT', 'https://test-payment.momo.vn/v2/gateway/api/create'),
        'redirect_url' => env('MOMO_REDIRECT_URL', rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/checkout'),
        'ipn_url' => env('MOMO_IPN_URL', rtrim(env('APP_URL', 'http://localhost:8080'), '/') . '/api/v1/payment/momo/ipn'),
        'request_type' => env('MOMO_REQUEST_TYPE', 'captureWallet'),
        'timeout' => env('MOMO_TIMEOUT', 15),
    ],

    'sepay' => [
        'webhook_secret' => env('SEPAY_WEBHOOK_SECRET'),
        'webhook_auth_method' => env('SEPAY_WEBHOOK_AUTH_METHOD', 'hmac'),
        'payment_code_prefix' => env('QR_PAYMENT_CODE_PREFIX', 'TEESIK'),
        'bank_bin' => env('QR_BANK_BIN'),
        'bank_account_number' => env('QR_BANK_ACCOUNT_NUMBER'),
        'bank_account_name' => env('QR_BANK_ACCOUNT_NAME'),
    ],

];
