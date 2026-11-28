<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DOKU Payment Gateway Configuration (Production)
    |--------------------------------------------------------------------------
    |
    | Seluruh kredensial DOKU dibaca dari file .env tanpa hardcode di kode.
    |
    */

    'base_url' => rtrim((string) env('DOKU_BASE_URL', 'https://api.doku.com'), '/'),

    'client_id' => (string) env('DOKU_CLIENT_ID', ''),

    'secret_key' => (string) env('DOKU_SECRET_KEY', ''),

    'api_key' => (string) env('DOKU_API_KEY', ''),

    'payment_due_date' => (int) env('DOKU_PAYMENT_DUE_DATE', 60),

    'callback_url' => env('DOKU_CALLBACK_URL'),

    'callback_url_cancel' => env('DOKU_CALLBACK_URL_CANCEL'),

    'webhook_url' => env('DOKU_WEBHOOK_URL'),
];
