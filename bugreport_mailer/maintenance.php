<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/mail_queue.php';

$logFile = MAIL_QUEUE_DEFAULT_LOG_FILE;
$lockHandle = null;

try {
    $config = loadMailerConfig(__DIR__ . '/config.php');
    $logFile = $config['log_file'];

    $lockHandle = acquireProcessLock($config['maintenance']['lock_file']);
    $pdo = openQueueDatabase($config);
    runQueueMaintenance($pdo, $config, $logFile);

    echo "Maintenance completed\n";
} catch (Throwable $throwable) {
    writeMailerLog(
        $logFile,
        sprintf(
            'Maintenance failed: %s in %s:%d',
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        )
    );
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        releaseProcessLock($lockHandle);
    }
}
