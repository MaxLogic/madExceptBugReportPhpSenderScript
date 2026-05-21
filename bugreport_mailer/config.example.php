<?php

// Copy this file to config.php on the deployment host and fill in real routing.
return [
    'sender' => 'noreply@example.com',
    'my_email' => 'support@example.com',
    'log_file' => __DIR__ . '/bugreport_mailer.log',
    'storage_dir' => __DIR__ . '/bugreports',
    'database_path' => __DIR__ . '/runtime/bugreport_mailer.sqlite',

    // Symfony Mailer DSN. Use 'native://default' for PHP mail().
    // For authenticated SMTP, keep credentials only in local config.php.
    'mailer_dsn' => 'native://default',
    'log_rotation_max_bytes' => 5 * 1024 * 1024,

    'request_limits' => [
        'per_minute' => 3,
        'per_hour' => 30,
        'max_request_bytes' => 10 * 1024 * 1024,
        'max_attachment_count' => 4,
        'max_attachment_bytes' => 8 * 1024 * 1024,
    ],

    'worker' => [
        'batch_size' => 10,
        'retry_schedule_seconds' => [600, 1800, 7200, 21600],
        'lock_file' => __DIR__ . '/runtime/send_queued_reports.lock',
        'sleep_between_messages_ms' => 0,
    ],

    'maintenance' => [
        'lock_file' => __DIR__ . '/runtime/maintenance.lock',
        'stale_sending_after_seconds' => 900,
        'sent_report_retention_days' => 30,
        'dead_report_retention_days' => 30,
        'log_retention_days' => 14,
    ],

    'customers' => [
        // 'customer_identifier' => [ recipients... ]
        'example-customer' => [
            'support@example.com',
        ],
    ],
];
