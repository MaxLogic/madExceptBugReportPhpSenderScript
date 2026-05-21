<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/mail_queue.php';

$logFile = MAIL_QUEUE_DEFAULT_LOG_FILE;

try {
    $config = loadMailerConfig(__DIR__ . '/config.php');
    $logFile = $config['log_file'];

    rotateLogFile($logFile, (int) $config['log_rotation_max_bytes']);
    writeMailerLog($logFile, '--- Bugreport Upload Attempt ---');

    enforceHttps($config, $logFile);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        httpError($logFile, 'Invalid request method', 405);
    }

    $pdo = openQueueDatabase($config);
    $reportId = enqueueCurrentRequest($pdo, $config, $logFile);

    writeMailerLog($logFile, 'Accepted queued bugreport ' . $reportId . ' with legacy SUCCESS response.');
    http_response_code(200);
    echo 'SUCCESS';
} catch (Throwable $throwable) {
    httpError(
        $logFile,
        'Internal server error',
        500,
        sprintf(
            'Unhandled runtime error: %s in %s:%d',
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        )
    );
}
