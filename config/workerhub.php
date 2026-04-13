<?php

return [
    'queues' => [
        'default' => env('WORKERHUB_DEFAULT_QUEUE', 'migration-default'),
        'high_priority' => env('WORKERHUB_HIGH_PRIORITY_QUEUE', 'migration-high'),
        'integration' => env('WORKERHUB_INTEGRATION_QUEUE', 'integration'),
    ],

    'tasks' => [
        'document_migration' => [
            'queue' => env('WORKERHUB_DOCUMENT_MIGRATION_QUEUE', 'migration-default'),
            'high_priority_queue' => env('WORKERHUB_DOCUMENT_MIGRATION_HIGH_QUEUE', 'migration-high'),
            'tries' => (int) env('WORKERHUB_DOCUMENT_MIGRATION_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_DOCUMENT_MIGRATION_TIMEOUT', 300),
        ],
    ],

    'kafka' => [
        'brokers' => env('KAFKA_BROKERS', 'redpanda:9092'),
        'client_id' => env('KAFKA_CLIENT_ID', 'workerhub'),
        'connect_timeout' => (float) env('KAFKA_CONNECT_TIMEOUT', 10),
        'send_timeout' => (float) env('KAFKA_SEND_TIMEOUT', 10),
        'recv_timeout' => (float) env('KAFKA_RECV_TIMEOUT', 10),
        'produce_retry' => (int) env('KAFKA_PRODUCE_RETRY', 3),
        'produce_retry_sleep' => (float) env('KAFKA_PRODUCE_RETRY_SLEEP', 0.1),
        'consumer_group' => env('KAFKA_CONSUMER_GROUP', 'workerhub-task-consumers'),
        'consumer_interval' => (float) env('KAFKA_CONSUMER_INTERVAL', 1),
        'consumer_session_timeout' => (float) env('KAFKA_CONSUMER_SESSION_TIMEOUT', 60),
        'consumer_rebalance_timeout' => (float) env('KAFKA_CONSUMER_REBALANCE_TIMEOUT', 60),
        'consumer_heartbeat' => (float) env('KAFKA_CONSUMER_HEARTBEAT', 3),
        'topics' => [
            'requests' => env('KAFKA_TOPIC_REQUESTS', 'workerhub.tasks.requests'),
            'results' => env('KAFKA_TOPIC_RESULTS', 'workerhub.tasks.results'),
            'failures' => env('KAFKA_TOPIC_FAILURES', 'workerhub.tasks.failures'),
            'dead_letters' => env('KAFKA_TOPIC_DEAD_LETTERS', 'workerhub.tasks.dead_letters'),
        ],
    ],

    'broadcasting' => [
        'channel' => env('WORKERHUB_BROADCAST_CHANNEL', 'workerhub.monitor'),
        'task_channel_prefix' => env('WORKERHUB_TASK_BROADCAST_PREFIX', 'workerhub.tasks'),
    ],

    'operations' => [
        'access_token' => env('WORKERHUB_OPERATIONS_TOKEN', ''),
        'allowed_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WORKERHUB_OPERATIONS_ALLOWED_EMAILS', ''))
        ))),
    ],

    'notifications' => [
        'enabled' => (bool) env('WORKERHUB_NOTIFICATIONS_ENABLED', true),
        'notify_on_completed' => (bool) env('WORKERHUB_NOTIFY_ON_COMPLETED', false),
        'notify_on_failed' => (bool) env('WORKERHUB_NOTIFY_ON_FAILED', true),
        'mail_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WORKERHUB_NOTIFICATION_EMAILS', ''))
        ))),
        'database_user_ids' => array_values(array_filter(array_map(
            static fn (string $value) => trim($value) === '' ? null : (int) trim($value),
            explode(',', (string) env('WORKERHUB_NOTIFICATION_USER_IDS', ''))
        ))),
    ],
];
