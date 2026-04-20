<?php

return [
    'queues' => [
        'default' => env('WORKERHUB_DEFAULT_QUEUE', 'migration-default'),
        'high_priority' => env('WORKERHUB_HIGH_PRIORITY_QUEUE', 'migration-high'),
        'integration' => env('WORKERHUB_INTEGRATION_QUEUE', 'integration'),
        'receipts_default' => env('WORKERHUB_RECEIPTS_QUEUE', 'receipts-default'),
        'receipts_high' => env('WORKERHUB_RECEIPTS_HIGH_QUEUE', 'receipts-high'),
        'sales_orders_default' => env('WORKERHUB_SALES_ORDERS_QUEUE', 'sales-orders-default'),
        'sales_orders_high' => env('WORKERHUB_SALES_ORDERS_HIGH_QUEUE', 'sales-orders-high'),
        'invoices_default' => env('WORKERHUB_INVOICES_QUEUE', 'invoices-default'),
        'invoices_high' => env('WORKERHUB_INVOICES_HIGH_QUEUE', 'invoices-high'),
        'customers_default' => env('WORKERHUB_CUSTOMERS_QUEUE', 'customers-default'),
        'customers_high' => env('WORKERHUB_CUSTOMERS_HIGH_QUEUE', 'customers-high'),
        'general_default' => env('WORKERHUB_GENERAL_QUEUE', 'general-default'),
        'general_high' => env('WORKERHUB_GENERAL_HIGH_QUEUE', 'general-high'),
    ],

    'tasks' => [
        'document_migration' => [
            'queue' => env('WORKERHUB_DOCUMENT_MIGRATION_QUEUE', 'migration-default'),
            'high_priority_queue' => env('WORKERHUB_DOCUMENT_MIGRATION_HIGH_QUEUE', 'migration-high'),
            'tries' => (int) env('WORKERHUB_DOCUMENT_MIGRATION_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_DOCUMENT_MIGRATION_TIMEOUT', 300),
        ],
        'receipt_migration' => [
            'queue' => env('WORKERHUB_RECEIPT_MIGRATION_QUEUE', env('WORKERHUB_DOCUMENT_MIGRATION_QUEUE', 'migration-default')),
            'high_priority_queue' => env('WORKERHUB_RECEIPT_MIGRATION_HIGH_QUEUE', env('WORKERHUB_DOCUMENT_MIGRATION_HIGH_QUEUE', 'migration-high')),
            'tries' => (int) env('WORKERHUB_RECEIPT_MIGRATION_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_RECEIPT_MIGRATION_TIMEOUT', 300),
        ],
        'order_migration' => [
            'queue' => env('WORKERHUB_ORDER_MIGRATION_QUEUE', env('WORKERHUB_SALES_ORDERS_QUEUE', 'sales-orders-default')),
            'high_priority_queue' => env('WORKERHUB_ORDER_MIGRATION_HIGH_QUEUE', env('WORKERHUB_SALES_ORDERS_HIGH_QUEUE', 'sales-orders-high')),
            'tries' => (int) env('WORKERHUB_ORDER_MIGRATION_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_ORDER_MIGRATION_TIMEOUT', 300),
        ],
    ],

    'kafka' => [
        'brokers' => env('KAFKA_BROKERS', 'redpanda:9092'),
        'client_id' => env('KAFKA_CLIENT_ID', 'workerhub'),
        'publish_enabled' => filter_var(env('KAFKA_PUBLISH_ENABLED', true), FILTER_VALIDATE_BOOL),
        'direct_dispatch_fallback' => filter_var(env('WORKERHUB_KAFKA_DIRECT_DISPATCH_FALLBACK', false), FILTER_VALIDATE_BOOL),
        'suppress_publish_failures' => filter_var(env('WORKERHUB_KAFKA_SUPPRESS_PUBLISH_FAILURES', false), FILTER_VALIDATE_BOOL),
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
            'external_execution_prefix' => env('KAFKA_TOPIC_EXTERNAL_EXECUTION_PREFIX', 'workerhub.runtime'),
        ],
    ],

    'broadcasting' => [
        'channel' => env('WORKERHUB_BROADCAST_CHANNEL', 'workerhub.monitor'),
        'task_channel_prefix' => env('WORKERHUB_TASK_BROADCAST_PREFIX', 'workerhub.tasks'),
    ],

    'operations' => [
        'access_token' => env('WORKERHUB_OPERATIONS_TOKEN', ''),
        'runtime_shared_token' => env('WORKERHUB_RUNTIME_SHARED_TOKEN', env('WORKERHUB_OPERATIONS_TOKEN', '')),
        'allow_token_fallback' => filter_var(env('WORKERHUB_ALLOW_TOKEN_FALLBACK', true), FILTER_VALIDATE_BOOL),
        'allow_local_bypass' => filter_var(env('WORKERHUB_ALLOW_LOCAL_BYPASS', true), FILTER_VALIDATE_BOOL),
        'allowed_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WORKERHUB_OPERATIONS_ALLOWED_EMAILS', ''))
        ))),
    ],

    'backoffice' => [
        'base_url' => rtrim((string) env('BACKOFFICE_BASE_URL', ''), '/'),
        'auth_endpoint' => env('BACKOFFICE_AUTH_ENDPOINT', '/api/internal/workerhub/operators/authenticate'),
        'health_endpoint' => env('BACKOFFICE_HEALTH_ENDPOINT', '/api/internal/workerhub/operators/health'),
        'auth_timeout' => (float) env('BACKOFFICE_AUTH_TIMEOUT', 5),
        'admin_role_id' => (int) env('BACKOFFICE_ADMIN_ROLE_ID', 20),
        'shared_token' => env('BACKOFFICE_SHARED_TOKEN', ''),
        'session_key' => env('WORKERHUB_OPERATOR_SESSION_KEY', 'workerhub.operator'),
    ],

    'health' => [
        'dead_letters_alert_threshold' => (int) env('WORKERHUB_DEAD_LETTERS_ALERT_THRESHOLD', 25),
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

    'receipts' => [
        'source_connections' => [
            'sqlsrv' => env('WORKERHUB_RECEIPT_SOURCE_SQLSRV_CONNECTION', 'source_sqlsrv'),
            'test' => env('WORKERHUB_RECEIPT_SOURCE_TEST_CONNECTION', 'source_test'),
        ],
        'views' => [
            'header' => env('WORKERHUB_RECEIPT_HEADER_VIEW', 'prototipos.v_prototipos_recibos_encabezado_sala_ventas'),
            'payments' => env('WORKERHUB_RECEIPT_PAYMENTS_VIEW', 'prototipos.v_prototipos_recibos_caja'),
        ],
        'pre_migration' => [
            'enabled' => filter_var(env('WORKERHUB_RECEIPT_PRE_MIGRATION_ENABLED', true), FILTER_VALIDATE_BOOL),
            'table' => env('WORKERHUB_RECEIPT_TABLE', 'pos.recibos_encabezado'),
            'functions' => [
                'legalized_amount' => env('WORKERHUB_RECEIPT_LEGALIZED_AMOUNT_FUNCTION', 'pos.fun_recibos_wompi_valor_legalizado'),
                'expired_without_payment' => env('WORKERHUB_RECEIPT_EXPIRED_WOMPI_FUNCTION', 'pos.fun_recibos_wompi_es_sin_pago_vencido'),
            ],
        ],
        'customer_sync' => [
            'enabled' => filter_var(env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
            'skip_enterprise_operational_centers' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_SKIP_COS', 'A40,A06'))
            ))),
            'tables' => [
                'receipts' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_RECEIPTS_TABLE', env('WORKERHUB_RECEIPT_TABLE', 'pos.recibos_encabezado')),
                'customers' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_CUSTOMERS_TABLE', 'pos.clientes'),
                'customer_classes' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_CLASSES_TABLE', 'pos.clase_de_cliente'),
                'id_types' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_ID_TYPES_TABLE', 'maestros_uno.untipoid_catalogo_tipos_identificacion'),
                'type_equivalences' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_TYPE_EQUIVALENCES_TABLE', 'prototipos.equivalencia_tipo_cliente'),
                'cities_ica' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_CITIES_ICA_TABLE', 'prototipos.ciudades_ica'),
                'customer_class_groups' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_CLASS_GROUPS_TABLE', 'maestros.clase_cliente_grupo'),
                'customer_class_master' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_CLASS_MASTER_TABLE', 'maestros.clase_cliente'),
            ],
            'enterprise_tables' => [
                'third_parties' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_ENTERPRISE_THIRD_PARTIES_TABLE', 'SiesaEnterprise.dbo.t200_mm_terceros'),
                'clients' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_ENTERPRISE_CLIENTS_TABLE', 'SiesaEnterprise.dbo.t201_mm_clientes'),
                'client_criteria' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_ENTERPRISE_CLIENT_CRITERIA_TABLE', 'SiesaEnterprise.dbo.t207_mm_criterios_clientes'),
                'major_criteria' => env('WORKERHUB_RECEIPT_CUSTOMER_SYNC_ENTERPRISE_MAJOR_CRITERIA_TABLE', 'SiesaEnterprise.dbo.t206_mm_criterios_mayores'),
            ],
        ],
        'cross_reference' => [
            'enabled' => filter_var(env('WORKERHUB_RECEIPT_CROSS_REFERENCE_GUARD_ENABLED', false), FILTER_VALIDATE_BOOL),
            'mode' => env('WORKERHUB_RECEIPT_CROSS_REFERENCE_MODE', 'strict'),
            'auxiliary_id' => env('WORKERHUB_RECEIPT_CROSS_REFERENCE_AUXILIARY_ID', '28050505'),
            'unit' => env('WORKERHUB_RECEIPT_CROSS_REFERENCE_UNIT', '02'),
            'enterprise_tables' => [
                'open_balances' => env('WORKERHUB_RECEIPT_CROSS_REFERENCE_OPEN_BALANCES_TABLE', 'SiesaEnterprise.dbo.t353_co_saldo_abierto'),
                'auxiliaries' => env('WORKERHUB_RECEIPT_CROSS_REFERENCE_AUXILIARIES_TABLE', 'SiesaEnterprise.dbo.t253_co_auxiliares'),
            ],
        ],
        'enterprise_state' => [
            'tables' => [
                'accounting_documents' => env('WORKERHUB_RECEIPT_ENTERPRISE_ACCOUNTING_DOCUMENTS_TABLE', 'SiesaEnterprise.dbo.t350_co_docto_contable'),
                'cash_receipts' => env('WORKERHUB_RECEIPT_ENTERPRISE_CASH_RECEIPTS_TABLE', 'SiesaEnterprise.dbo.t357_co_ingresos_caja'),
            ],
        ],
        'legacy_state' => [
            'enabled' => filter_var(env('WORKERHUB_RECEIPT_LEGACY_STATE_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
            'table' => env('WORKERHUB_RECEIPT_LEGACY_STATE_TABLE', env('WORKERHUB_RECEIPT_TABLE', 'pos.recibos_encabezado')),
            'service_user_id' => (int) env('WORKERHUB_RECEIPT_LEGACY_STATE_SERVICE_USER_ID', 285),
        ],
    ],

    'orders' => [
        'source_connections' => [
            'sqlsrv' => env('WORKERHUB_ORDER_SOURCE_SQLSRV_CONNECTION', 'source_sqlsrv'),
            'test' => env('WORKERHUB_ORDER_SOURCE_TEST_CONNECTION', 'source_test'),
        ],
        'views' => [
            'header' => env('WORKERHUB_ORDER_HEADER_VIEW', 'prototipos.v_prototipo_pedidos_encabezado_sala_ventas'),
            'detail' => env('WORKERHUB_ORDER_DETAIL_VIEW', 'prototipos.v_prototipo_pedidos_detalle_sala_ventas_sin_kit'),
        ],
        'detail' => [
            'prepare_procedure' => env('WORKERHUB_ORDER_DETAIL_PREPARE_PROCEDURE', 'pos.usp_pedidos_detalle_items_con_kits'),
            'price_list' => env('WORKERHUB_ORDER_DETAIL_PRICE_LIST', 'C37'),
            'special_reference_warehouses' => [
                'dispverde' => env('WORKERHUB_ORDER_DISPVERDE_WAREHOUSE', '00346'),
            ],
            'special_motives' => [
                'fletenal' => env('WORKERHUB_ORDER_FLETENAL_MOTIVE', ''),
                'serepcol' => env('WORKERHUB_ORDER_SEREPCOL_MOTIVE', ''),
            ],
        ],
        'customer_sync' => [
            'enabled' => filter_var(env('WORKERHUB_ORDER_CUSTOMER_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
        ],
        'tables' => [
            'orders' => env('WORKERHUB_ORDER_TABLE', 'pos.pedidos_encabezado'),
            'order_details' => env('WORKERHUB_ORDER_DETAILS_TABLE', 'pos.pedidos_detalle'),
            'history' => env('WORKERHUB_ORDER_HISTORY_TABLE', 'pos.pedidos_historia_migracion'),
            'chain_third_parties' => env('WORKERHUB_ORDER_CHAIN_THIRD_PARTIES_TABLE', 'ventas.cadenas_tercero'),
            'chain_orders' => env('WORKERHUB_ORDER_CHAIN_ORDERS_TABLE', 'ventas.pedidos_cadenas'),
            'stores' => env('WORKERHUB_ORDER_STORES_TABLE', 'laravel_comodisimos.dbo.stores'),
            'companies' => env('WORKERHUB_ORDER_COMPANIES_TABLE', 'laravel_comodisimos.dbo.companies'),
            'item_units' => env('WORKERHUB_ORDER_ITEM_UNITS_TABLE', 'maestros_uno.cmitems_catalogo_de_items'),
            'activation_periods' => env('WORKERHUB_ORDER_ACTIVATION_PERIODS_TABLE', 'contabilidad.er_salas_fechas_activacion'),
        ],
        'enterprise_state' => [
            'tables' => [
                'orders' => env('WORKERHUB_ORDER_ENTERPRISE_ORDERS_TABLE', 'SiesaEnterprise.dbo.t430_cm_pv_docto'),
                'order_lines' => env('WORKERHUB_ORDER_ENTERPRISE_ORDER_LINES_TABLE', 'SiesaEnterprise.dbo.t431_cm_pv_movto'),
                'items' => env('WORKERHUB_ORDER_ENTERPRISE_ITEMS_TABLE', 'SiesaEnterprise.dbo.t120_mc_items'),
            ],
        ],
        'legacy_state' => [
            'enabled' => filter_var(env('WORKERHUB_ORDER_LEGACY_STATE_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
            'service_user_id' => (int) env('WORKERHUB_ORDER_LEGACY_STATE_SERVICE_USER_ID', 285),
            'verification_threshold' => (float) env('WORKERHUB_ORDER_LEGACY_VERIFICATION_THRESHOLD', 1000),
        ],
    ],

    'processes' => [
        'receipts' => [
            'label' => 'Recibos',
            'description' => 'Migraciones de recaudo y aplicaciones de recibo.',
            'keywords' => ['recibo', 'recibos', 'cxc recibo', 'importarrecibos'],
            'runtime' => env('WORKERHUB_RECEIPTS_RUNTIME', 'php'),
            'queues' => [
                'default' => env('WORKERHUB_RECEIPTS_QUEUE', 'receipts-default'),
                'high' => env('WORKERHUB_RECEIPTS_HIGH_QUEUE', 'receipts-high'),
            ],
            'topics' => [
                'requests' => env('KAFKA_TOPIC_REQUESTS', 'workerhub.tasks.requests'),
                'execution' => env('KAFKA_TOPIC_RECEIPTS_EXECUTION', 'workerhub.runtime.php.receipts'),
            ],
            'tries' => (int) env('WORKERHUB_RECEIPTS_TRIES', env('WORKERHUB_RECEIPT_MIGRATION_TRIES', 3)),
            'timeout' => (int) env('WORKERHUB_RECEIPTS_TIMEOUT', env('WORKERHUB_RECEIPT_MIGRATION_TIMEOUT', 300)),
        ],
        'sales_orders' => [
            'label' => 'Pedidos',
            'description' => 'Pedidos comerciales y sus reintentos.',
            'keywords' => ['pedido', 'pedidos', 'order', 'sales order'],
            'runtime' => env('WORKERHUB_SALES_ORDERS_RUNTIME', 'php'),
            'queues' => [
                'default' => env('WORKERHUB_SALES_ORDERS_QUEUE', 'sales-orders-default'),
                'high' => env('WORKERHUB_SALES_ORDERS_HIGH_QUEUE', 'sales-orders-high'),
            ],
            'topics' => [
                'requests' => env('KAFKA_TOPIC_REQUESTS', 'workerhub.tasks.requests'),
                'execution' => env('KAFKA_TOPIC_SALES_ORDERS_EXECUTION', 'workerhub.runtime.php.sales_orders'),
            ],
            'tries' => (int) env('WORKERHUB_SALES_ORDERS_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_SALES_ORDERS_TIMEOUT', 300),
        ],
        'invoices' => [
            'label' => 'Facturas',
            'description' => 'Facturas y documentos relacionados.',
            'keywords' => ['factura', 'facturas', 'invoice', 'cxcfactura'],
            'runtime' => env('WORKERHUB_INVOICES_RUNTIME', 'php'),
            'queues' => [
                'default' => env('WORKERHUB_INVOICES_QUEUE', 'invoices-default'),
                'high' => env('WORKERHUB_INVOICES_HIGH_QUEUE', 'invoices-high'),
            ],
            'topics' => [
                'requests' => env('KAFKA_TOPIC_REQUESTS', 'workerhub.tasks.requests'),
                'execution' => env('KAFKA_TOPIC_INVOICES_EXECUTION', 'workerhub.runtime.php.invoices'),
            ],
            'tries' => (int) env('WORKERHUB_INVOICES_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_INVOICES_TIMEOUT', 300),
        ],
        'customers' => [
            'label' => 'Clientes',
            'description' => 'Clientes, terceros y sucursales.',
            'keywords' => ['cliente', 'clientes', 'tercero', 'terceros', 'sucursal'],
            'runtime' => env('WORKERHUB_CUSTOMERS_RUNTIME', 'python'),
            'queues' => [
                'default' => env('WORKERHUB_CUSTOMERS_QUEUE', 'customers-default'),
                'high' => env('WORKERHUB_CUSTOMERS_HIGH_QUEUE', 'customers-high'),
            ],
            'topics' => [
                'requests' => env('KAFKA_TOPIC_REQUESTS', 'workerhub.tasks.requests'),
                'execution' => env('KAFKA_TOPIC_CUSTOMERS_EXECUTION', 'workerhub.runtime.python.customers'),
            ],
            'tries' => (int) env('WORKERHUB_CUSTOMERS_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_CUSTOMERS_TIMEOUT', 300),
        ],
        'general' => [
            'label' => 'General',
            'description' => 'Procesos sin clasificacion explicita.',
            'keywords' => [],
            'runtime' => env('WORKERHUB_GENERAL_RUNTIME', 'php'),
            'queues' => [
                'default' => env('WORKERHUB_GENERAL_QUEUE', 'general-default'),
                'high' => env('WORKERHUB_GENERAL_HIGH_QUEUE', 'general-high'),
            ],
            'topics' => [
                'requests' => env('KAFKA_TOPIC_REQUESTS', 'workerhub.tasks.requests'),
                'execution' => env('KAFKA_TOPIC_GENERAL_EXECUTION', 'workerhub.runtime.php.general'),
            ],
            'tries' => (int) env('WORKERHUB_GENERAL_TRIES', 3),
            'timeout' => (int) env('WORKERHUB_GENERAL_TIMEOUT', 180),
        ],
    ],
];
