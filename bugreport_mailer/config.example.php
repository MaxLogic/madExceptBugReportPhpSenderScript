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
        'per_customer_per_hour' => 50,
        'global_per_hour' => 100,
        'max_queued_reports' => 300,
        'max_request_bytes' => 50 * 1024 * 1024,
        'max_attachment_count' => 4,
        'max_attachment_bytes' => 50 * 1024 * 1024,
        'max_storage_bytes' => 250 * 1024 * 1024,
        'allowed_attachment_extensions' => ['zip', 'txt', 'log', 'png', 'jpg', 'jpeg'],
    ],

    'security' => [
        'require_https' => true,
        'trust_forwarded_proto' => true,
    ],

    'monitoring' => [
        'event_log_file' => __DIR__ . '/runtime/security_events.log',
        'last_digest_file' => __DIR__ . '/runtime/security_digest_sent_at',
        'event_attachment_dir' => __DIR__ . '/runtime/security_event_attachments',
        'digest_interval_seconds' => 3600,
        'max_digest_lines' => 200,
        'max_diagnostic_bytes' => 1024 * 1024,
        'max_diagnostic_storage_bytes' => 10 * 1024 * 1024,
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
        // Backwards-compatible form: no upload token required.
        'example-customer' => [
            'support@example.com',
        ],

        // Recommended form for new deployments.
        // If upload_token_sha256 is configured, clients must send the plain
        // token in the uploadToken form field or X-Bugreport-Token header.
        // Replace the all-zero placeholder with hash('sha256', $plainToken).
        'token-protected-customer' => [
            'recipients' => [
                'support@example.com',
            ],
            'upload_token_sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
        ],
    ],
];
