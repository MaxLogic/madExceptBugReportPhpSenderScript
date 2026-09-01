<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/mail_queue.php';

use Symfony\Component\Mime\Email;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . "\nMissing: " . $needle . "\nText: " . $haystack);
    }
}

function removeTestDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        throw new RuntimeException('Cannot list test directory: ' . $directory);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeTestDirectory($path);
        } elseif (!unlink($path)) {
            throw new RuntimeException('Cannot remove test file: ' . $path);
        }
    }

    if (!rmdir($directory)) {
        throw new RuntimeException('Cannot remove test directory: ' . $directory);
    }
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bugreport-mailer-security-' . bin2hex(random_bytes(6));
$eventLog = $testRoot . DIRECTORY_SEPARATOR . 'security_events.log';
$appLog = $testRoot . DIRECTORY_SEPARATOR . 'bugreport_mailer.log';
$attachmentDir = $testRoot . DIRECTORY_SEPARATOR . 'security_attachments';
$zipPath = $testRoot . DIRECTORY_SEPARATOR . 'report.zip';
$outsidePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bugreport-mailer-outside-' . bin2hex(random_bytes(6)) . '.txt';

if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Cannot create test directory');
}

try {
    $loadedConfig = loadMailerConfig(dirname(__DIR__) . '/config.example.php');
    assertSameValue(50 * 1024 * 1024, $loadedConfig['request_limits']['max_request_bytes'], 'The request limit should be 50 MiB.');
    assertSameValue(50 * 1024 * 1024, $loadedConfig['request_limits']['max_attachment_bytes'], 'The attachment limit should be 50 MiB.');
    assertSameValue(1024 * 1024, $loadedConfig['monitoring']['max_diagnostic_bytes'], 'The diagnostic limit should default to 1 MiB.');
    assertSameValue(10 * 1024 * 1024, $loadedConfig['monitoring']['max_diagnostic_storage_bytes'], 'Diagnostic spool storage should be capped at 10 MiB.');

    $archive = new PharData($zipPath);
    $archive->addFromString('logs/bugreport.log', 'short log');
    $archive->addFromString('nested/BUGREPORT.TXT', str_repeat('x', 33));
    unset($archive);

    $_SERVER = [
        'REMOTE_ADDR' => '203.0.113.7',
        'CONTENT_LENGTH' => (string) (51 * 1024 * 1024),
    ];
    $_POST = [
        'MailFrom' => 'sender@example.com',
        'MailFromName' => "Ada\r\nLovelace",
        'MailSubject' => "Crash while saving\r\nInjected header",
        'customerIdentifier' => 'example-customer',
    ];
    $_FILES = [];

    $uploads = [[
        'name' => 'report.zip',
        'tmp_name' => $zipPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($zipPath),
    ]];
    $config = [
        'sender' => 'mailer@example.com',
        'my_email' => 'owner@example.com',
        'monitoring' => [
            'event_log_file' => $eventLog,
            'event_attachment_dir' => $attachmentDir,
            'max_diagnostic_bytes' => 16,
        ],
    ];

    recordSecurityEvent($config, $appLog, 'request_too_large', 'Rejected oversized request.', $uploads);

    $lines = file($eventLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assertSameValue(1, is_array($lines) ? count($lines) : 0, 'One structured security event should be recorded.');

    $event = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('request_too_large', $event['type'], 'The event type should be retained.');
    assertSameValue('203.0.113.7', $event['ip'], 'The remote IP should be retained.');
    assertSameValue('sender@example.com', $event['details']['from_address'], 'The sender address should be recorded.');
    assertSameValue('Ada Lovelace', $event['details']['from_name'], 'Newlines should be removed from the sender name.');
    assertSameValue('Crash while saving Injected header', $event['details']['subject'], 'Newlines should be removed from the subject.');
    assertSameValue(51 * 1024 * 1024, $event['details']['request_size_bytes'], 'The declared request size should be recorded.');
    assertSameValue('report.zip', $event['details']['attachments'][0]['name'], 'The attachment name should be recorded.');
    assertSameValue(filesize($zipPath), $event['details']['attachments'][0]['size_bytes'], 'The attachment size should be recorded.');
    assertSameValue('attached', $event['details']['zip_diagnostic'], 'A bounded diagnostic should be attached.');
    assertSameValue('bugreport.log', strtolower($event['diagnostic_attachment']['name']), 'An oversized bugreport.txt should fall back to bugreport.log.');
    assertSameValue('short log', file_get_contents($event['diagnostic_attachment']['path']), 'The diagnostic content should be preserved.');

    unset($_POST['MailFromName']);
    $_POST['FromName'] = 'Grace Hopper';
    $fallbackDetails = collectRequestSecurityDetails($uploads);
    assertSameValue('Grace Hopper', $fallbackDetails['from_name'], 'FromName should be used when MailFromName is absent.');
    unset($_POST['FromName']);
    $_POST['MailFromName'] = "Ada\r\nLovelace";

    $logMessage = formatSecurityEventForLog($event);
    assertContainsText('from_address="sender@example.com"', $logMessage, 'The persistent log message should contain the sender address.');
    assertContainsText('attachment="report.zip', $logMessage, 'The persistent log message should contain attachment details.');

    $legacyLine = '[2026-08-31 19:15:46] [203.0.113.8] [legacy_event] Legacy event.';
    $email = buildSecurityDigestEmail($config, [$lines[0], $legacyLine], 1);
    assertSameValue(true, $email instanceof Email, 'The digest builder should return an email.');
    $body = (string) $email->getTextBody();
    assertContainsText('From address: sender@example.com', $body, 'The digest should show the sender address.');
    assertContainsText('From name: Ada Lovelace', $body, 'The digest should show the sender name.');
    assertContainsText('Subject: Crash while saving Injected header', $body, 'The digest should show the subject.');
    assertContainsText('Request size: 53477376 bytes', $body, 'The digest should show the request size.');
    assertContainsText('report.zip', $body, 'The digest should show attachment metadata.');
    assertContainsText($legacyLine, $body, 'Legacy plain-text events should remain readable.');
    assertContainsText('1 older event(s) were omitted.', $body, 'The digest should report omitted events.');
    assertSameValue(1, count($email->getAttachments()), 'The diagnostic text should be attached to the digest.');

    cleanupSecurityEventAttachments($lines, $attachmentDir);
    assertSameValue(false, file_exists($event['diagnostic_attachment']['path']), 'The spooled diagnostic should be removed after successful mail.');

    file_put_contents($outsidePath, 'must stay');
    $forgedEvent = $event;
    $forgedEvent['diagnostic_attachment']['path'] = $outsidePath;
    cleanupSecurityEventAttachments([json_encode($forgedEvent, JSON_THROW_ON_ERROR)], $attachmentDir);
    assertSameValue(true, file_exists($outsidePath), 'Cleanup must not delete files outside the diagnostic directory.');

    echo "security_events_test: PASS\n";
} finally {
    $_SERVER = [];
    $_POST = [];
    $_FILES = [];
    if (file_exists($outsidePath)) {
        unlink($outsidePath);
    }
    removeTestDirectory($testRoot);
}
