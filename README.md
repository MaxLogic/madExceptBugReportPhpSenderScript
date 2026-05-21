# madExcept Bug Report PHP Mailer

Small PHP endpoint for receiving [madExcept](https://www.madshi.net/) bug reports from Delphi applications, queueing them in SQLite, and sending them by email from the server.

The intended flow is:

1. A Delphi application configured with madExcept posts a bug report to `bugreport_mailer.php`.
2. The PHP endpoint validates the customer identifier, stores the report and uploaded attachments locally, and returns `SUCCESS`.
3. A scheduled worker runs `send_queued_reports.php` and sends queued reports to the recipients configured for that customer.
4. A scheduled maintenance task runs `maintenance.php` to recover stale sends and prune old reports/logs.

## Why This Exists

Sending bug reports directly from a Delphi desktop application is often fragile. SMTP from the client requires credentials in or near the application, can be blocked by firewalls or antivirus software, and depends on each customer's network allowing outbound mail traffic. Other client-side send methods have similar reliability and support problems.

This script moves that responsibility to a server-side endpoint. The Delphi application only needs to upload the madExcept report over HTTPS. Mail credentials and recipient routing stay on the server, where they can be changed without rebuilding the Delphi application.

The queue also provides operational control that is hard to enforce from client applications: per-IP throttling, attachment and request size limits, retry scheduling, stale-send recovery, and cleanup of old reports.

## Requirements

- madExcept for the Delphi client application: <https://www.madshi.net/>
- PHP 7.4 or newer
- PHP extensions:
  - `pdo_sqlite`
  - `fileinfo`
- Composer
- A mail transport available to Symfony Mailer:
  - `native://default` for the host's PHP mail setup, or
  - an SMTP DSN stored only in the local, untracked `config.php`

## Repository Layout

```text
bugreport_mailer/
  bugreport_mailer.php        HTTP upload endpoint
  mail_queue.php              queue, validation, storage, and mail logic
  send_queued_reports.php     CLI worker that sends queued reports
  maintenance.php             CLI maintenance task
  config.example.php          sanitized runtime config template
  .user.ini.example           optional PHP runtime config template
  tests/test.sh               curl-based smoke test
```

Runtime-only files are intentionally ignored by Git:

- `bugreport_mailer/config.php`
- `bugreport_mailer/.user.ini`
- `bugreport_mailer/vendor/`
- `bugreport_mailer/runtime/`
- `bugreport_mailer/bugreports/`
- `bugreport_mailer/*.log`
- `bugreport_mailer/tests/*.zip`
- `.env`

## Installation

Install Composer dependencies from the application directory:

```bash
cd bugreport_mailer
composer install --no-dev --optimize-autoloader
```

Create the local runtime config:

```bash
cp config.example.php config.php
```

Edit `config.php` on the deployment host:

- `sender`: email address used as the sender.
- `my_email`: default recipient for all reports.
- `mailer_dsn`: Symfony Mailer DSN. Keep real SMTP credentials only in this untracked file.
- `customers`: map of accepted `customerIdentifier` values to recipient lists.
- `storage_dir`: directory for uploaded report attachments.
- `database_path`: SQLite queue database path.
- `request_limits`: upload and rate limits.
- `worker`: queue worker batch size, retry schedule, and lock file.
- `maintenance`: stale-send recovery and retention settings.

If the host supports per-directory `.user.ini`, copy the example and adjust it locally:

```bash
cp .user.ini.example .user.ini
```

The example enables PHP error logging, writes errors to `php_errors.log`, reports all errors, and keeps errors hidden from HTTP responses.

## Web Server

Deploy the `bugreport_mailer/` directory to a PHP-capable web host and expose:

```text
https://example.com/bugreport_mailer/bugreport_mailer.php
```

The endpoint accepts only `POST` requests. On success it returns:

```text
SUCCESS
```

Required form fields:

- `customerIdentifier`: routing key that must exist in `config.php`.
- `MailSubject`: subject used for the queued email.
- `MailBody`: text body used for the queued email.

Optional form fields:

- `MailFrom`: validated email address used as the reply-to address.
- Any other scalar fields: stored and appended under `--- Sender Information ---`.
- Uploaded files: stored as attachments and sent by the worker.

The script stores reports before sending them. It does not send email during the HTTP request.

## Queue Worker

Run the worker from cron or another scheduler:

```bash
php /path/to/bugreport_mailer/send_queued_reports.php
```

It claims queued reports, sends them through Symfony Mailer, marks successful reports as `sent`, and reschedules failures using the configured retry schedule. A lock file prevents concurrent worker runs.

Run maintenance periodically:

```bash
php /path/to/bugreport_mailer/maintenance.php
```

Maintenance resets stale `sending` reports, removes expired sent/dead reports and their attachments, and prunes rotated logs.

## Smoke Test

Create a local `.env` from `.env.example` if you use the included test script:

```bash
cp .env.example .env
```

Set `PUBLIC_URL` to the deployed directory URL, not the script URL:

```text
PUBLIC_URL=https://example.com/bugreport_mailer/
```

Run:

```bash
cd bugreport_mailer/tests
CUSTOMER_IDENTIFIER=example-customer ./test.sh
```

The script reads `PUBLIC_URL`, posts to `bugreport_mailer.php`, creates a temporary synthetic bug report attachment if none is provided, and uploads `screen.png` as a second attachment.

Optional overrides:

```bash
CUSTOMER_IDENTIFIER=example-customer \
BUGREPORT_ATTACHMENT=/path/to/bugreport.zip \
SCREENSHOT_ATTACHMENT=/path/to/screen.png \
./test.sh
```

## Delphi / madExcept Setup

The companion Delphi units `maxLogic.MadExcept` and `MaxLogic.MadExceptStrings` are recommended for Delphi projects that already use the MaxLogicFoundation code.

Typical setup:

```delphi
uses
  System.SysUtils,
  maxLogic.MadExcept;

procedure ConfigureBugReports;
begin
  AdjustMadExcept(IncludeTrailingPathDelimiter(ExtractFilePath(ParamStr(0))));

  SetUpWebUpload(
    'https://example.com/bugreport_mailer/bugreport_mailer.php',
    'example-customer'
  );

  AddFieldToBugReportHeader('Environment', 'Production');
  AddFieldToBugReportHeader('Channel', 'Stable');
end;
```

Important details:

- The URL passed to `SetUpWebUpload` should be the full `bugreport_mailer.php` URL.
- The tenant argument becomes madExcept's `customerIdentifier` field and must match a key in the server-side `customers` config.
- The helper enables `UploadToCustomScript`, disables other send methods by default, sets `BugReportField=bugreport`, forces SSL, and uses the default HTTPS port.
- Extra fields added with `AddFieldToBugReportHeader` are included in the madExcept report/header data.
- `MaxLogic.MadExceptStrings` is specifically the German localization layer for madExcept UI strings and report body text. Use it for German-language applications; otherwise skip it or provide your own localization.

For projects that do not use MaxLogicFoundation, configure the equivalent madExcept settings directly:

```delphi
var
  Settings: IMEModuleSettings;
begin
  Settings := MESettings;
  Settings.UploadToCustomScript := True;
  Settings.HttpServer := 'https://example.com/bugreport_mailer/bugreport_mailer.php';
  Settings.HttpSsl := True;
  Settings.HttpPort := 0;
  Settings.AdditionalFields['BugReportField'] := 'bugreport';
  Settings.AdditionalFields['customerIdentifier'] := 'example-customer';
end;
```

## Security Notes

- Do not commit `config.php`, `.env`, `.user.ini`, logs, queued reports, uploaded archives, or `vendor/`.
- Keep customer routing and real email addresses in `config.php`, not in `config.example.php`.
- Keep SMTP credentials in `config.php` or server environment only.
- Keep the upload endpoint behind HTTPS.
- Treat uploaded reports as sensitive diagnostic data. They may include paths, screenshots, user data, stack traces, and local machine details.
- Run the worker and maintenance scripts as CLI tasks, not through public HTTP endpoints.
