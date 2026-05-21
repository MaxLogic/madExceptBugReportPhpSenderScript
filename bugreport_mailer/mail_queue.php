<?php

declare(strict_types=1);

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

const MAIL_QUEUE_DEFAULT_LOG_FILE = __DIR__ . '/bugreport_mailer.log';

/**
 * @return array<string, mixed>
 */
function loadMailerConfig(string $configPath): array
{
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array');
    }

    $defaults = [
        'log_file' => MAIL_QUEUE_DEFAULT_LOG_FILE,
        'storage_dir' => __DIR__ . '/bugreports',
        'database_path' => __DIR__ . '/runtime/bugreport_mailer.sqlite',
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
    ];

    $config = array_replace_recursive($defaults, $config);

    foreach (['sender', 'my_email', 'mailer_dsn', 'log_file', 'storage_dir', 'database_path'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
            throw new RuntimeException("Missing or invalid config value: {$key}");
        }
    }

    if (!filter_var($config['sender'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid sender email address in config');
    }

    if (!filter_var($config['my_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid my_email address in config');
    }

    if (!isset($config['customers']) || !is_array($config['customers'])) {
        throw new RuntimeException('Missing or invalid customers mapping in config');
    }

    assertPositiveIntConfig($config['log_rotation_max_bytes'], 'log_rotation_max_bytes');
    validateNestedIntConfig($config, ['request_limits', 'per_minute']);
    validateNestedIntConfig($config, ['request_limits', 'per_hour']);
    validateNestedIntConfig($config, ['request_limits', 'max_request_bytes']);
    validateNestedIntConfig($config, ['request_limits', 'max_attachment_count']);
    validateNestedIntConfig($config, ['request_limits', 'max_attachment_bytes']);
    validateNestedIntConfig($config, ['worker', 'batch_size']);
    validateNestedIntConfig($config, ['worker', 'sleep_between_messages_ms'], true);
    validateNestedIntConfig($config, ['maintenance', 'stale_sending_after_seconds']);
    validateNestedIntConfig($config, ['maintenance', 'sent_report_retention_days']);
    validateNestedIntConfig($config, ['maintenance', 'dead_report_retention_days']);
    validateNestedIntConfig($config, ['maintenance', 'log_retention_days']);
    if (!isset($config['worker']['retry_schedule_seconds']) || !is_array($config['worker']['retry_schedule_seconds']) || $config['worker']['retry_schedule_seconds'] === []) {
        throw new RuntimeException('worker.retry_schedule_seconds must be a non-empty array');
    }

    foreach ($config['worker']['retry_schedule_seconds'] as $delay) {
        assertPositiveIntConfig($delay, 'worker.retry_schedule_seconds');
    }

    return $config;
}

/**
 * @param mixed $value
 */
function assertPositiveIntConfig($value, string $name, bool $allowZero = false): void
{
    if (!is_int($value) || ($allowZero ? $value < 0 : $value <= 0)) {
        throw new RuntimeException("Invalid integer config value: {$name}");
    }
}

/**
 * @param array<string, mixed> $config
 * @param list<string> $path
 */
function validateNestedIntConfig(array $config, array $path, bool $allowZero = false): void
{
    $value = $config;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            throw new RuntimeException('Missing config value: ' . implode('.', $path));
        }

        $value = $value[$segment];
    }

    assertPositiveIntConfig($value, implode('.', $path), $allowZero);
}

function utcNow(): string
{
    return gmdate('Y-m-d H:i:s');
}

function utcOffsetSeconds(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

function ensureDirectoryExists(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!@mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create directory: ' . $path);
    }
}

function writeMailerLog(?string $logFile, string $message): void
{
    $target = is_string($logFile) && $logFile !== '' ? $logFile : MAIL_QUEUE_DEFAULT_LOG_FILE;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $ip, $message);

    if (@file_put_contents($target, $line, FILE_APPEND) === false) {
        error_log(trim($line));
    }
}

function rotateLogFile(string $logFile, int $maxBytes): void
{
    if (!is_file($logFile)) {
        return;
    }

    $size = @filesize($logFile);
    if ($size === false || $size <= $maxBytes) {
        return;
    }

    @rename($logFile, $logFile . '.' . time());
}

function removeExpiredRotatedLogs(string $logFile, int $retentionDays): void
{
    $pattern = $logFile . '.*';
    $files = glob($pattern);
    if ($files === false || $files === []) {
        return;
    }

    $cutoff = time() - ($retentionDays * 86400);
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

function httpError(?string $logFile, string $clientMessage, int $httpCode, ?string $logMessage = null): void
{
    writeMailerLog($logFile, $logMessage ?? ('Error response: ' . $clientMessage));
    http_response_code($httpCode);
    echo $clientMessage;
    exit;
}

/**
 * @return resource
 */
function acquireProcessLock(string $lockFile)
{
    ensureDirectoryExists(dirname($lockFile));

    $handle = fopen($lockFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Cannot open lock file: ' . $lockFile);
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('Another process already holds lock: ' . $lockFile);
    }

    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);

    return $handle;
}

/**
 * @param resource $lockHandle
 */
function releaseProcessLock($lockHandle): void
{
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

/**
 * @param array<string, mixed> $config
 */
function openQueueDatabase(array $config): PDO
{
    $databasePath = $config['database_path'];
    ensureDirectoryExists(dirname($databasePath));

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('pdo_sqlite extension is required');
    }

    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    initializeQueueSchema($pdo);

    return $pdo;
}

function initializeQueueSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            received_at TEXT NOT NULL,
            remote_ip TEXT NOT NULL,
            customer_identifier TEXT NOT NULL,
            mail_subject TEXT NOT NULL,
            mail_body TEXT NOT NULL,
            mail_from TEXT,
            extra_post_json TEXT NOT NULL,
            recipients_json TEXT NOT NULL,
            status TEXT NOT NULL,
            attempt_count INTEGER NOT NULL DEFAULT 0,
            next_attempt_at TEXT NOT NULL,
            locked_at TEXT,
            last_error TEXT,
            sent_at TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            stored_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            mime_type TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(report_id) REFERENCES reports(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_status_next_attempt_at ON reports(status, next_attempt_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_remote_ip_received_at ON reports(remote_ip, received_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attachments_report_id ON attachments(report_id)');
}

function requirePostStringValue(string $field, ?string $logFile): string
{
    if (!isset($_POST[$field]) || !is_string($_POST[$field]) || trim($_POST[$field]) === '') {
        httpError($logFile, 'Missing required field: ' . $field, 400);
    }

    return $_POST[$field];
}

/**
 * @param mixed $value
 */
function formatPostValueForStorage($value): string
{
    if (is_scalar($value) || $value === null) {
        return (string) $value;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '[unserializable value]' : $encoded;
}

/**
 * @return array<string, string>
 */
function buildExtraPostFields(): array
{
    $extra = [];
    foreach ($_POST as $key => $value) {
        if (in_array($key, ['BugReportField', 'MailSubject', 'MailBody', 'customerIdentifier'], true)) {
            continue;
        }

        $extra[(string) $key] = formatPostValueForStorage($value);
    }

    return $extra;
}

function buildStoredMailBody(string $baseBody, array $extraFields): string
{
    if ($extraFields === []) {
        return $baseBody;
    }

    $lines = [];
    foreach ($extraFields as $key => $value) {
        $lines[] = $key . ': ' . $value;
    }

    return $baseBody . "\n\n--- Sender Information ---\n" . implode("\n", $lines);
}

/**
 * @param array<string, mixed> $config
 * @return list<string>
 */
function resolveRecipients(array $config, string $customerIdentifier, ?string $logFile): array
{
    $recipients = [$config['my_email']];

    $customerRecipients = $config['customers'][$customerIdentifier];
    if (!is_array($customerRecipients)) {
        writeMailerLog($logFile, "WARNING: Customer '{$customerIdentifier}' has non-array recipients in config.");
    } else {
        $recipients = array_merge($recipients, $customerRecipients);
    }

    $validRecipients = [];
    foreach (array_unique($recipients) as $recipient) {
        if (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $validRecipients[] = $recipient;
            continue;
        }

        writeMailerLog($logFile, 'WARNING: Ignoring invalid recipient from config.');
    }

    if ($validRecipients === []) {
        throw new RuntimeException('No valid recipients resolved from config');
    }

    return $validRecipients;
}

/**
 * @param array<string, mixed> $config
 */
function ensureKnownCustomerIdentifier(array $config, string $customerIdentifier, ?string $logFile): void
{
    if (!array_key_exists($customerIdentifier, $config['customers'])) {
        httpError(
            $logFile,
            'Unknown customer identifier',
            400,
            "Rejected unknown customer identifier '{$customerIdentifier}'."
        );
    }
}

/**
 * @return list<array{name:string,tmp_name:string,error:int,size:int}>
 */
function normalizeIncomingUploads(array $files): array
{
    $normalized = [];

    foreach ($files as $file) {
        if (!is_array($file) || !array_key_exists('error', $file)) {
            continue;
        }

        if (is_array($file['error'])) {
            $count = count($file['error']);
            for ($index = 0; $index < $count; $index++) {
                $normalized[] = [
                    'name' => is_string($file['name'][$index] ?? null) ? $file['name'][$index] : '',
                    'tmp_name' => is_string($file['tmp_name'][$index] ?? null) ? $file['tmp_name'][$index] : '',
                    'error' => is_int($file['error'][$index] ?? null) ? $file['error'][$index] : UPLOAD_ERR_NO_FILE,
                    'size' => is_int($file['size'][$index] ?? null) ? $file['size'][$index] : 0,
                ];
            }

            continue;
        }

        $normalized[] = [
            'name' => is_string($file['name'] ?? null) ? $file['name'] : '',
            'tmp_name' => is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '',
            'error' => is_int($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            'size' => is_int($file['size'] ?? null) ? $file['size'] : 0,
        ];
    }

    return $normalized;
}

function uploadErrorLabel(int $errorCode): string
{
    $labels = [
        UPLOAD_ERR_INI_SIZE => 'file exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'file exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'no file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'temporary folder is missing',
        UPLOAD_ERR_CANT_WRITE => 'failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'upload stopped by a PHP extension',
    ];

    return isset($labels[$errorCode]) ? $labels[$errorCode] : 'unknown upload error';
}

/**
 * @param array<string, mixed> $config
 * @param list<array{name:string,tmp_name:string,error:int,size:int}> $uploads
 */
function validateRequestLimits(array $config, array $uploads, string $remoteIp, PDO $pdo, ?string $logFile): void
{
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > (int) $config['request_limits']['max_request_bytes']) {
        httpError($logFile, 'Request too large', 413);
    }

    $actualUploads = [];
    foreach ($uploads as $upload) {
        if ($upload['error'] !== UPLOAD_ERR_NO_FILE) {
            $actualUploads[] = $upload;
        }
    }

    if (count($actualUploads) > (int) $config['request_limits']['max_attachment_count']) {
        httpError($logFile, 'Too many attachments', 400);
    }

    foreach ($actualUploads as $upload) {
        if ($upload['size'] > (int) $config['request_limits']['max_attachment_bytes']) {
            httpError($logFile, 'Attachment too large', 413, "Rejected oversized attachment '{$upload['name']}'");
        }

        if ($upload['error'] !== UPLOAD_ERR_OK) {
            httpError(
                $logFile,
                'Attachment upload failed',
                400,
                sprintf("Upload error for '%s': %s", $upload['name'], uploadErrorLabel($upload['error']))
            );
        }
    }

    $perMinute = countRecentReportsByIp($pdo, $remoteIp, 60);
    if ($perMinute >= (int) $config['request_limits']['per_minute']) {
        httpError($logFile, 'Rate limit exceeded', 429, 'Per-minute rate limit exceeded for IP ' . $remoteIp);
    }

    $perHour = countRecentReportsByIp($pdo, $remoteIp, 3600);
    if ($perHour >= (int) $config['request_limits']['per_hour']) {
        httpError($logFile, 'Rate limit exceeded', 429, 'Per-hour rate limit exceeded for IP ' . $remoteIp);
    }
}

function countRecentReportsByIp(PDO $pdo, string $remoteIp, int $windowSeconds): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM reports
         WHERE remote_ip = :remote_ip
           AND received_at >= :cutoff'
    );
    $statement->execute([
        ':remote_ip' => $remoteIp,
        ':cutoff' => utcOffsetSeconds(-$windowSeconds),
    ]);

    return (int) $statement->fetchColumn();
}

function detectMimeType(string $path): ?string
{
    if (!extension_loaded('fileinfo')) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return null;
    }

    $mimeType = finfo_file($finfo, $path);
    finfo_close($finfo);

    return is_string($mimeType) ? $mimeType : null;
}

/**
 * @param list<array{name:string,tmp_name:string,error:int,size:int}> $uploads
 * @return list<array<string, mixed>>
 */
function storeUploads(int $reportId, array $uploads, string $storageDir, ?string $logFile): array
{
    ensureDirectoryExists($storageDir);

    $stored = [];
    foreach ($uploads as $upload) {
        if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($upload['tmp_name'] === '' || !is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException("Uploaded file '{$upload['name']}' has no valid temporary upload path");
        }

        $originalName = basename($upload['name']);
        if ($originalName === '') {
            $originalName = 'attachment.bin';
        }

        $storedPath = sprintf(
            '%s/%s_%d_%s_%s',
            rtrim($storageDir, '/'),
            gmdate('Ymd_His'),
            $reportId,
            bin2hex(random_bytes(4)),
            $originalName
        );

        if (!@move_uploaded_file($upload['tmp_name'], $storedPath)) {
            throw new RuntimeException("Failed to move uploaded file '{$originalName}'");
        }

        $mimeType = detectMimeType($storedPath);
        $stored[] = [
            'stored_path' => $storedPath,
            'original_name' => $originalName,
            'size_bytes' => (int) @filesize($storedPath),
            'mime_type' => $mimeType,
        ];

        writeMailerLog($logFile, "Stored uploaded file '{$originalName}' for queued report {$reportId}");
    }

    return $stored;
}

/**
 * @param list<array<string, mixed>> $attachments
 */
function persistAttachments(PDO $pdo, int $reportId, array $attachments): void
{
    $statement = $pdo->prepare(
        'INSERT INTO attachments (report_id, stored_path, original_name, size_bytes, mime_type, created_at)
         VALUES (:report_id, :stored_path, :original_name, :size_bytes, :mime_type, :created_at)'
    );

    foreach ($attachments as $attachment) {
        $statement->execute([
            ':report_id' => $reportId,
            ':stored_path' => $attachment['stored_path'],
            ':original_name' => $attachment['original_name'],
            ':size_bytes' => $attachment['size_bytes'],
            ':mime_type' => $attachment['mime_type'],
            ':created_at' => utcNow(),
        ]);
    }
}

function deleteStoredFiles(array $attachments): void
{
    foreach ($attachments as $attachment) {
        if (isset($attachment['stored_path']) && is_string($attachment['stored_path']) && is_file($attachment['stored_path'])) {
            @unlink($attachment['stored_path']);
        }
    }
}

/**
 * @param array<string, mixed> $config
 */
function enqueueCurrentRequest(PDO $pdo, array $config, ?string $logFile): int
{
    $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'Unknown IP';
    $mailSubject = requirePostStringValue('MailSubject', $logFile);
    $mailBody = requirePostStringValue('MailBody', $logFile);
    $customerIdentifier = requirePostStringValue('customerIdentifier', $logFile);
    $mailFrom = isset($_POST['MailFrom']) && is_string($_POST['MailFrom']) && trim($_POST['MailFrom']) !== '' ? $_POST['MailFrom'] : null;

    if ($mailFrom !== null && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
        httpError($logFile, 'Invalid MailFrom address', 400);
    }

    ensureKnownCustomerIdentifier($config, $customerIdentifier, $logFile);

    $uploads = normalizeIncomingUploads($_FILES);
    validateRequestLimits($config, $uploads, $remoteIp, $pdo, $logFile);

    $extraFields = buildExtraPostFields();
    $storedBody = buildStoredMailBody($mailBody, $extraFields);
    $recipients = resolveRecipients($config, $customerIdentifier, $logFile);
    $receivedAt = utcNow();

    $pdo->beginTransaction();
    $storedAttachments = [];

    try {
        $statement = $pdo->prepare(
            'INSERT INTO reports (
                received_at,
                remote_ip,
                customer_identifier,
                mail_subject,
                mail_body,
                mail_from,
                extra_post_json,
                recipients_json,
                status,
                attempt_count,
                next_attempt_at
            ) VALUES (
                :received_at,
                :remote_ip,
                :customer_identifier,
                :mail_subject,
                :mail_body,
                :mail_from,
                :extra_post_json,
                :recipients_json,
                :status,
                0,
                :next_attempt_at
            )'
        );
        $statement->execute([
            ':received_at' => $receivedAt,
            ':remote_ip' => $remoteIp,
            ':customer_identifier' => $customerIdentifier,
            ':mail_subject' => $mailSubject,
            ':mail_body' => $storedBody,
            ':mail_from' => $mailFrom,
            ':extra_post_json' => json_encode($extraFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':recipients_json' => json_encode($recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => 'queued',
            ':next_attempt_at' => $receivedAt,
        ]);

        $reportId = (int) $pdo->lastInsertId();
        $storedAttachments = storeUploads($reportId, $uploads, $config['storage_dir'], $logFile);
        persistAttachments($pdo, $reportId, $storedAttachments);

        $pdo->commit();

        writeMailerLog(
            $logFile,
            sprintf(
                "Queued bugreport %d for customer '%s' from IP %s with %d attachment(s)",
                $reportId,
                $customerIdentifier,
                $remoteIp,
                count($storedAttachments)
            )
        );

        return $reportId;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        deleteStoredFiles($storedAttachments);
        throw $throwable;
    }
}

/**
 * @param array<string, mixed> $config
 * @return list<array<string, mixed>>
 */
function claimQueuedReports(PDO $pdo, array $config): array
{
    $limit = (int) $config['worker']['batch_size'];
    $now = utcNow();

    $pdo->beginTransaction();

    try {
        $select = $pdo->prepare(
            'SELECT * FROM reports
             WHERE status = :status
               AND next_attempt_at <= :next_attempt_at
             ORDER BY id ASC
             LIMIT :limit'
        );
        $select->bindValue(':status', 'queued', PDO::PARAM_STR);
        $select->bindValue(':next_attempt_at', $now, PDO::PARAM_STR);
        $select->bindValue(':limit', $limit, PDO::PARAM_INT);
        $select->execute();

        $reports = $select->fetchAll();
        if ($reports === false || $reports === []) {
            $pdo->commit();
            return [];
        }

        $update = $pdo->prepare(
            'UPDATE reports
             SET status = :status, locked_at = :locked_at
             WHERE id = :id'
        );

        foreach ($reports as $report) {
            $update->execute([
                ':status' => 'sending',
                ':locked_at' => $now,
                ':id' => $report['id'],
            ]);
        }

        $pdo->commit();
        return $reports;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function loadAttachmentsForReport(PDO $pdo, int $reportId): array
{
    $statement = $pdo->prepare('SELECT * FROM attachments WHERE report_id = :report_id ORDER BY id ASC');
    $statement->execute([':report_id' => $reportId]);
    $attachments = $statement->fetchAll();

    return $attachments === false ? [] : $attachments;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $report
 * @param list<array<string, mixed>> $attachments
 */
function sendQueuedReport(array $config, array $report, array $attachments): void
{
    $email = (new Email())
        ->from($config['sender'])
        ->subject((string) $report['mail_subject'])
        ->text((string) $report['mail_body']);

    if (!empty($report['mail_from']) && filter_var($report['mail_from'], FILTER_VALIDATE_EMAIL)) {
        $email->replyTo((string) $report['mail_from']);
    }

    $recipients = json_decode((string) $report['recipients_json'], true);
    if (!is_array($recipients) || $recipients === []) {
        throw new RuntimeException('Queued report has invalid recipients_json');
    }

    foreach ($recipients as $recipient) {
        if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Queued report contains invalid recipient');
        }

        $email->addTo($recipient);
    }

    foreach ($attachments as $attachment) {
        if (!isset($attachment['stored_path']) || !is_string($attachment['stored_path']) || !is_file($attachment['stored_path'])) {
            throw new RuntimeException('Attachment file is missing for queued report ' . $report['id']);
        }

        $email->attachFromPath($attachment['stored_path'], (string) $attachment['original_name']);
    }

    $transport = Transport::fromDsn($config['mailer_dsn']);
    $mailer = new Mailer($transport);
    $mailer->send($email);
}

/**
 * @param array<string, mixed> $config
 */
function processQueuedReports(PDO $pdo, array $config, ?string $logFile): int
{
    $reports = claimQueuedReports($pdo, $config);
    if ($reports === []) {
        writeMailerLog($logFile, 'Queue worker found no queued reports ready to send.');
        return 0;
    }

    $processed = 0;
    foreach ($reports as $report) {
        $attachments = loadAttachmentsForReport($pdo, (int) $report['id']);

        try {
            sendQueuedReport($config, $report, $attachments);
            markReportSent($pdo, (int) $report['id']);
            writeMailerLog($logFile, 'Sent queued bugreport ' . $report['id']);
        } catch (Throwable $throwable) {
            rescheduleFailedReport($pdo, (int) $report['id'], (int) $report['attempt_count'] + 1, $config, $throwable->getMessage());
            writeMailerLog($logFile, 'Failed queued bugreport ' . $report['id'] . ': ' . $throwable->getMessage());
        }

        $processed++;

        $sleepMs = (int) $config['worker']['sleep_between_messages_ms'];
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    return $processed;
}

function markReportSent(PDO $pdo, int $reportId): void
{
    $statement = $pdo->prepare(
        'UPDATE reports
         SET status = :status,
             locked_at = NULL,
             last_error = NULL,
             sent_at = :sent_at
         WHERE id = :id'
    );
    $statement->execute([
        ':status' => 'sent',
        ':sent_at' => utcNow(),
        ':id' => $reportId,
    ]);
}

/**
 * @param array<string, mixed> $config
 */
function rescheduleFailedReport(PDO $pdo, int $reportId, int $attemptCount, array $config, string $errorMessage): void
{
    $schedule = $config['worker']['retry_schedule_seconds'];
    $maxAttempts = count($schedule) + 1;

    if ($attemptCount >= $maxAttempts) {
        $statement = $pdo->prepare(
            'UPDATE reports
             SET status = :status,
                 attempt_count = :attempt_count,
                 locked_at = NULL,
                 last_error = :last_error
             WHERE id = :id'
        );
        $statement->execute([
            ':status' => 'dead',
            ':attempt_count' => $attemptCount,
            ':last_error' => $errorMessage,
            ':id' => $reportId,
        ]);
        return;
    }

    $delay = (int) $schedule[$attemptCount - 1];
    $statement = $pdo->prepare(
        'UPDATE reports
         SET status = :status,
             attempt_count = :attempt_count,
             next_attempt_at = :next_attempt_at,
             locked_at = NULL,
             last_error = :last_error
         WHERE id = :id'
    );
    $statement->execute([
        ':status' => 'queued',
        ':attempt_count' => $attemptCount,
        ':next_attempt_at' => utcOffsetSeconds($delay),
        ':last_error' => $errorMessage,
        ':id' => $reportId,
    ]);
}

/**
 * @param array<string, mixed> $config
 */
function runQueueMaintenance(PDO $pdo, array $config, ?string $logFile): void
{
    rotateLogFile($config['log_file'], (int) $config['log_rotation_max_bytes']);
    removeExpiredRotatedLogs($config['log_file'], (int) $config['maintenance']['log_retention_days']);

    resetStaleSendingReports($pdo, (int) $config['maintenance']['stale_sending_after_seconds']);
    cleanupRetiredReports($pdo, 'sent', (int) $config['maintenance']['sent_report_retention_days'], $logFile);
    cleanupRetiredReports($pdo, 'dead', (int) $config['maintenance']['dead_report_retention_days'], $logFile);

    writeMailerLog($logFile, 'Queue maintenance finished successfully.');
}

function resetStaleSendingReports(PDO $pdo, int $staleAfterSeconds): void
{
    $statement = $pdo->prepare(
        'UPDATE reports
         SET status = :status,
             locked_at = NULL,
             next_attempt_at = :next_attempt_at,
             last_error = COALESCE(last_error, :fallback_error)
         WHERE status = :sending_status
           AND locked_at IS NOT NULL
           AND locked_at <= :cutoff'
    );
    $statement->execute([
        ':status' => 'queued',
        ':next_attempt_at' => utcNow(),
        ':fallback_error' => 'Recovered stale sending state',
        ':sending_status' => 'sending',
        ':cutoff' => utcOffsetSeconds(-$staleAfterSeconds),
    ]);
}

function cleanupRetiredReports(PDO $pdo, string $status, int $retentionDays, ?string $logFile): void
{
    $cutoff = utcOffsetSeconds(-($retentionDays * 86400));
    $statement = $pdo->prepare(
        'SELECT id FROM reports
         WHERE status = :status
           AND (
               (sent_at IS NOT NULL AND sent_at <= :cutoff)
               OR (sent_at IS NULL AND received_at <= :cutoff)
           )'
    );
    $statement->execute([
        ':status' => $status,
        ':cutoff' => $cutoff,
    ]);

    $reportIds = $statement->fetchAll(PDO::FETCH_COLUMN);
    if ($reportIds === false || $reportIds === []) {
        return;
    }

    $attachmentSelect = $pdo->prepare('SELECT stored_path FROM attachments WHERE report_id = :report_id');
    $attachmentDelete = $pdo->prepare('DELETE FROM attachments WHERE report_id = :report_id');
    $reportDelete = $pdo->prepare('DELETE FROM reports WHERE id = :id');

    foreach ($reportIds as $reportId) {
        $attachmentSelect->execute([':report_id' => $reportId]);
        $paths = $attachmentSelect->fetchAll(PDO::FETCH_COLUMN);
        if ($paths !== false) {
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $attachmentDelete->execute([':report_id' => $reportId]);
        $reportDelete->execute([':id' => $reportId]);
        writeMailerLog($logFile, 'Pruned retired report ' . $reportId . ' with status ' . $status);
    }
}
