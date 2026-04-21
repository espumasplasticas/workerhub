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

    'api' => [
        'base_url' => env('API_BASE_URL', 'http://127.0.0.1:3010'),
        'receipt_migration_notifications_endpoint' => env('API_RECEIPT_MIGRATION_NOTIFICATIONS_ENDPOINT', '/api/internal/workerhub/receipts/migrated'),
        'receipt_cancellation_notifications_endpoint' => env('API_RECEIPT_CANCELLATION_NOTIFICATIONS_ENDPOINT', '/api/internal/workerhub/receipts/cancelled'),
        'order_migration_notifications_endpoint' => env('API_ORDER_MIGRATION_NOTIFICATIONS_ENDPOINT', '/api/internal/workerhub/orders/migrated'),
        'order_cancellation_notifications_endpoint' => env('API_ORDER_CANCELLATION_NOTIFICATIONS_ENDPOINT', '/api/internal/workerhub/orders/cancelled'),
        'invoice_migration_notifications_endpoint' => env('API_INVOICE_MIGRATION_NOTIFICATIONS_ENDPOINT', '/api/internal/workerhub/invoices/migrated'),
        'timeout' => (int) env('API_TIMEOUT_SECONDS', 10),
        'workerhub_notification_token' => env('API_WORKERHUB_NOTIFICATION_TOKEN', ''),
    ],

];
