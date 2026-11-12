<?php

/**
 * Hostinger Business Email & SMTP Mailer (Zero-Dependency Pure PHP)
 * Supports Hostinger Mail (smtp.hostinger.com:465 SSL / 587 STARTTLS),
 * MySQL/SQLite `mail_settings` runtime persistence, .env fallback, and transactional templates.
 */

if (!function_exists('mailerStripCrlfPhp')) {
    function mailerStripCrlfPhp(?string $val): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($val ?? '')));
    }

    function mailerReadEnvPhp(string $key, string $default = ''): string
    {
        static $parsedEnv = null;
        if ($parsedEnv === null) {
            $parsedEnv = [];
            $envFile = __DIR__ . '/../.env';
            if (file_exists($envFile)) {
                $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $parsedEnv[trim($k)] = trim(trim($v), "\"'");
                }
            }
        }

        $sys = getenv($key);
        if ($sys !== false && $sys !== '') {
            return (string) $sys;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($parsedEnv[$key]) && $parsedEnv[$key] !== '') {
            return (string) $parsedEnv[$key];
        }
        return $default;
    }

    function isRealDeliverableEmailPhp(?string $email): bool
    {
        if (!$email || !is_string($email)) {
            return false;
        }
        $trimmed = strtolower(trim($email));
        if (!filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (str_ends_with($trimmed, '@phone.vendor.local')) {
            return false;
        }
        return true;
    }

    function isAutomatedPlaceholderDomainPhp(?string $email): bool
    {
        $lower = strtolower(trim((string) ($email ?? '')));
        return str_ends_with($lower, '@phone.vendor.local')
            || str_ends_with($lower, '@example.com')
            || str_ends_with($lower, '@php.test')
            || str_ends_with($lower, '@vendor.app')
            || str_ends_with($lower, '.test')
            || str_ends_with($lower, '.local');
    }

    function encodeMimeHeaderUtf8Php(string $str): string
    {
        $clean = mailerStripCrlfPhp($str);
        if ($clean === '') {
            return '';
        }
        if (preg_match('/^[\x20-\x7E]*$/', $clean)) {
            return $clean;
        }
        return '=?UTF-8?B?' . base64_encode($clean) . '?=';
    }

    function formatAddressHeaderPhp(string $address, string $displayName = ''): string
    {
        $cleanAddr = mailerStripCrlfPhp($address);
        $cleanName = str_replace('"', '', mailerStripCrlfPhp($displayName));
        if ($cleanName === '') {
            return $cleanAddr;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $cleanName)) {
            return '"' . $cleanName . '" <' . $cleanAddr . '>';
        }
        return encodeMimeHeaderUtf8Php($cleanName) . ' <' . $cleanAddr . '>';
    }

    function normalizeMailConfigPhp(array $raw = []): array
    {
        $host = mailerStripCrlfPhp((string) ($raw['host'] ?? $raw['smtp_host'] ?? mailerReadEnvPhp('MAIL_HOST', 'smtp.hostinger.com')));
        if ($host === '') {
            $host = 'smtp.hostinger.com';
        }
        $port = (int) ($raw['port'] ?? $raw['smtp_port'] ?? mailerReadEnvPhp('MAIL_PORT', '465'));
        if ($port <= 0) {
            $port = 465;
        }
        $enc = strtolower(mailerStripCrlfPhp((string) ($raw['encryption'] ?? $raw['smtp_encryption'] ?? mailerReadEnvPhp('MAIL_ENCRYPTION', ''))));
        if ($enc === '') {
            $enc = ($port === 587) ? 'tls' : 'ssl';
        }
        $username = mailerStripCrlfPhp((string) ($raw['username'] ?? $raw['smtp_username'] ?? mailerReadEnvPhp('MAIL_USERNAME', '')));
        $password = (string) ($raw['password'] ?? $raw['smtp_password'] ?? mailerReadEnvPhp('MAIL_PASSWORD', ''));
        $fromAddress = mailerStripCrlfPhp((string) ($raw['from_address'] ?? mailerReadEnvPhp('MAIL_FROM_ADDRESS', '')));
        if ($fromAddress === '') {
            $fromAddress = $username;
        }
        $fromName = mailerStripCrlfPhp((string) ($raw['from_name'] ?? mailerReadEnvPhp('MAIL_FROM_NAME', mailerReadEnvPhp('APP_NAME', 'Knowlipop Vendor'))));
        if ($fromName === '') {
            $fromName = 'Knowlipop Vendor';
        }
        $replyTo = mailerStripCrlfPhp((string) ($raw['reply_to'] ?? mailerReadEnvPhp('MAIL_REPLY_TO', '')));
        if ($replyTo === '') {
            $replyTo = $fromAddress;
        }
        $isActive = array_key_exists('is_active', $raw) ? ((int) $raw['is_active'] === 1) : true;
        $configured = $isActive && $host !== '' && $port > 0 && $username !== '' && $password !== '' && $fromAddress !== '';

        return [
            'mailer' => strtolower(mailerStripCrlfPhp((string) ($raw['mailer'] ?? mailerReadEnvPhp('MAIL_MAILER', 'smtp')))) ?: 'smtp',
            'host' => $host,
            'port' => $port,
            'encryption' => $enc,
            'username' => $username,
            'password' => $password,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'reply_to' => $replyTo,
            'is_active' => $isActive,
            'configured' => $configured,
            'source' => $raw['_source'] ?? 'env',
            'verify_peer' => array_key_exists('verify_peer', $raw) ? (bool) $raw['verify_peer'] : true,
        ];
    }

    function ensureMailSettingsSchemaPhp(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        try {
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS mail_settings (
                        id INTEGER PRIMARY KEY,
                        mailer TEXT NOT NULL DEFAULT 'smtp',
                        host TEXT NOT NULL DEFAULT 'smtp.hostinger.com',
                        port INTEGER NOT NULL DEFAULT 465,
                        encryption TEXT NOT NULL DEFAULT 'ssl',
                        username TEXT NULL DEFAULT NULL,
                        password TEXT NULL DEFAULT NULL,
                        from_address TEXT NULL DEFAULT NULL,
                        from_name TEXT NULL DEFAULT NULL,
                        reply_to TEXT NULL DEFAULT NULL,
                        is_active INTEGER NOT NULL DEFAULT 1,
                        updated_by INTEGER NULL DEFAULT NULL,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS mail_settings (
                        id INT UNSIGNED NOT NULL PRIMARY KEY,
                        mailer VARCHAR(30) NOT NULL DEFAULT 'smtp',
                        host VARCHAR(255) NOT NULL DEFAULT 'smtp.hostinger.com',
                        port INT NOT NULL DEFAULT 465,
                        encryption VARCHAR(20) NOT NULL DEFAULT 'ssl',
                        username VARCHAR(255) NULL DEFAULT NULL,
                        password TEXT NULL DEFAULT NULL,
                        from_address VARCHAR(255) NULL DEFAULT NULL,
                        from_name VARCHAR(255) NULL DEFAULT NULL,
                        reply_to VARCHAR(255) NULL DEFAULT NULL,
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
                        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            $ensured = true;
        } catch (Throwable $ignore) {
        }
    }

    function getEffectiveMailConfigPhp(?PDO $pdo = null, array $override = []): array
    {
        $envBase = normalizeMailConfigPhp(['_source' => 'env']);
        $dbRow = null;
        if ($pdo instanceof PDO) {
            try {
                ensureMailSettingsSchemaPhp($pdo);
                $stmt = $pdo->query("SELECT * FROM mail_settings WHERE id = 1 LIMIT 1");
                $fetched = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($fetched)) {
                    $dbRow = $fetched;
                }
            } catch (Throwable $ignore) {
            }
        }

        $base = $envBase;
        if ($dbRow && (int) ($dbRow['is_active'] ?? 1) === 1 && (!empty($dbRow['username']) || !empty($dbRow['password']))) {
            $base = normalizeMailConfigPhp([
                'mailer' => $dbRow['mailer'] ?: $envBase['mailer'],
                'host' => $dbRow['host'] ?: $envBase['host'],
                'port' => $dbRow['port'] ?: $envBase['port'],
                'encryption' => $dbRow['encryption'] ?: $envBase['encryption'],
                'username' => $dbRow['username'] ?: $envBase['username'],
                'password' => $dbRow['password'] ?: $envBase['password'],
                'from_address' => $dbRow['from_address'] ?: ($dbRow['username'] ?: $envBase['from_address']),
                'from_name' => $dbRow['from_name'] ?: $envBase['from_name'],
                'reply_to' => $dbRow['reply_to'] ?: ($dbRow['from_address'] ?: $envBase['reply_to']),
                'is_active' => $dbRow['is_active'],
                '_source' => 'database',
            ]);
        }

        if (!empty($override)) {
            $merged = array_merge($base, $override);
            if (!isset($override['password']) || (string) $override['password'] === '') {
                $merged['password'] = $base['password'];
            }
            $merged['_source'] = 'override';
            return normalizeMailConfigPhp($merged);
        }

        return $base;
    }

    function getPublicMailConfigSummaryPhp(?PDO $pdo = null): array
    {
        $cfg = getEffectiveMailConfigPhp($pdo);
        return [
            'provider' => 'hostinger_smtp',
            'mailer' => $cfg['mailer'],
            'host' => $cfg['host'],
            'port' => (int) $cfg['port'],
            'encryption' => $cfg['encryption'],
            'username' => $cfg['username'],
            'has_password' => $cfg['password'] !== '',
            'from_address' => $cfg['from_address'],
            'from_name' => $cfg['from_name'],
            'reply_to' => $cfg['reply_to'],
            'is_active' => (bool) $cfg['is_active'],
            'configured' => (bool) $cfg['configured'],
            'source' => $cfg['configured'] ? $cfg['source'] : 'unconfigured',
        ];
    }

    function saveMailConfigPhp(PDO $pdo, array $data, ?int $updatedBy = null): array
    {
        ensureMailSettingsSchemaPhp($pdo);
        $existing = getEffectiveMailConfigPhp($pdo);

        $host = mailerStripCrlfPhp((string) ($data['host'] ?? $existing['host'] ?? 'smtp.hostinger.com')) ?: 'smtp.hostinger.com';
        $port = (int) ($data['port'] ?? $existing['port'] ?? 465) ?: 465;
        $encryption = strtolower(mailerStripCrlfPhp((string) ($data['encryption'] ?? ($port === 587 ? 'tls' : 'ssl')))) ?: 'ssl';
        $username = mailerStripCrlfPhp((string) (array_key_exists('username', $data) ? $data['username'] : $existing['username']));
        $password = (isset($data['password']) && (string) $data['password'] !== '') ? (string) $data['password'] : (string) $existing['password'];
        $fromAddress = mailerStripCrlfPhp((string) ($data['from_address'] ?? $username ?: $existing['from_address']));
        $fromName = mailerStripCrlfPhp((string) ($data['from_name'] ?? $existing['from_name'] ?? 'Knowlipop Vendor')) ?: 'Knowlipop Vendor';
        $replyTo = mailerStripCrlfPhp((string) ($data['reply_to'] ?? $fromAddress ?: $existing['reply_to']));
        $isActive = array_key_exists('is_active', $data) ? ($data['is_active'] ? 1 : 0) : 1;

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO mail_settings (id, mailer, host, port, encryption, username, password, from_address, from_name, reply_to, is_active, updated_by, created_at, updated_at)
                VALUES (1, 'smtp', :host, :port, :enc, :user, :pass, :from_addr, :from_name, :reply_to, :active, :uby, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT(id) DO UPDATE SET
                    mailer = excluded.mailer,
                    host = excluded.host,
                    port = excluded.port,
                    encryption = excluded.encryption,
                    username = excluded.username,
                    password = excluded.password,
                    from_address = excluded.from_address,
                    from_name = excluded.from_name,
                    reply_to = excluded.reply_to,
                    is_active = excluded.is_active,
                    updated_by = excluded.updated_by,
                    updated_at = CURRENT_TIMESTAMP
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO mail_settings (id, mailer, host, port, encryption, username, password, from_address, from_name, reply_to, is_active, updated_by, created_at, updated_at)
                VALUES (1, 'smtp', :host, :port, :enc, :user, :pass, :from_addr, :from_name, :reply_to, :active, :uby, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    mailer = VALUES(mailer),
                    host = VALUES(host),
                    port = VALUES(port),
                    encryption = VALUES(encryption),
                    username = VALUES(username),
                    password = VALUES(password),
                    from_address = VALUES(from_address),
                    from_name = VALUES(from_name),
                    reply_to = VALUES(reply_to),
                    is_active = VALUES(is_active),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
        }
        $stmt->execute([
            ':host' => $host,
            ':port' => $port,
            ':enc' => $encryption,
            ':user' => $username,
            ':pass' => $password,
            ':from_addr' => $fromAddress,
            ':from_name' => $fromName,
            ':reply_to' => $replyTo,
            ':active' => $isActive,
            ':uby' => $updatedBy,
        ]);

        return getPublicMailConfigSummaryPhp($pdo);
    }

    function smtpReadReplyPhp($socket): array
    {
        $code = 0;
        $lines = [];
        while (!feof($socket)) {
            $line = fgets($socket, 8192);
            if ($line === false) {
                break;
            }
            $trimmed = rtrim($line, "\r\n");
            $lines[] = $trimmed;
            if (preg_match('/^(\d{3})([ -])(.*)$/', $trimmed, $m)) {
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }
        return ['code' => $code, 'text' => implode("\n", $lines)];
    }

    function smtpExpectPhp($socket, ?string $cmd, array $expectedCodes): array
    {
        if ($cmd !== null) {
            fwrite($socket, $cmd . "\r\n");
        }
        $reply = smtpReadReplyPhp($socket);
        if (!in_array($reply['code'], $expectedCodes, true)) {
            $verb = $cmd ? explode(' ', $cmd)[0] : 'CONNECT';
            throw new RuntimeException("SMTP error on {$verb}: [{$reply['code']}] {$reply['text']}");
        }
        return $reply;
    }

    function sendViaSmtpSocketPhp(array $cfg, array $toList, string $fromAddress, string $rawMessage, int $timeoutSec = 15): array
    {
        $useDirectSsl = ($cfg['encryption'] === 'ssl' || ((int) $cfg['port'] === 465 && $cfg['encryption'] !== 'tls' && $cfg['encryption'] !== 'none'));
        $transport = ($useDirectSsl ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int) $cfg['port'];
        $verifyPeer = $cfg['verify_peer'] ?? true;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer,
                'peer_name' => $cfg['host'],
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($transport, $errno, $errstr, $timeoutSec, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return ['ok' => false, 'error' => "Could not connect to {$transport}: {$errstr} ({$errno})"];
        }

        stream_set_timeout($socket, $timeoutSec);

        try {
            smtpExpectPhp($socket, null, [220]);
            $ehloDomain = str_contains($fromAddress, '@') ? explode('@', $fromAddress, 2)[1] : 'knowlipop.shop';
            $ehloReply = smtpExpectPhp($socket, "EHLO {$ehloDomain}", [250]);

            $enc = strtolower((string) ($cfg['encryption'] ?? ''));
            if ($enc === 'tls' || $enc === 'starttls' || ((int) $cfg['port'] === 587 && $enc !== 'none' && $enc !== 'ssl')) {
                smtpExpectPhp($socket, 'STARTTLS', [220]);
                $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if (!$cryptoOk) {
                    $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                }
                if (!$cryptoOk) {
                    throw new RuntimeException('STARTTLS cryptographic handshake failed.');
                }
                $ehloReply = smtpExpectPhp($socket, "EHLO {$ehloDomain}", [250]);
            }

            $supportsPlainOnly = preg_match('/AUTH\b[^\n]*\bPLAIN\b/i', $ehloReply['text']) && !preg_match('/AUTH\b[^\n]*\bLOGIN\b/i', $ehloReply['text']);
            if ($supportsPlainOnly) {
                $plain = base64_encode("\0" . $cfg['username'] . "\0" . $cfg['password']);
                smtpExpectPhp($socket, "AUTH PLAIN {$plain}", [235]);
            } else {
                smtpExpectPhp($socket, 'AUTH LOGIN', [334]);
                smtpExpectPhp($socket, base64_encode((string) $cfg['username']), [334]);
                smtpExpectPhp($socket, base64_encode((string) $cfg['password']), [235]);
            }

            smtpExpectPhp($socket, 'MAIL FROM:<' . mailerStripCrlfPhp($fromAddress) . '>', [250]);
            foreach ($toList as $rcpt) {
                smtpExpectPhp($socket, 'RCPT TO:<' . mailerStripCrlfPhp($rcpt) . '>', [250, 251]);
            }

            smtpExpectPhp($socket, 'DATA', [354]);
            $stuffed = preg_replace('/^\./m', '..', $rawMessage);
            fwrite($socket, $stuffed . "\r\n.\r\n");
            $dataReply = smtpExpectPhp($socket, null, [250]);

            try {
                smtpExpectPhp($socket, 'QUIT', [221, 250]);
            } catch (Throwable $ignore) {
            }
            @fclose($socket);
            return ['ok' => true, 'smtp_response' => $dataReply['text']];
        } catch (Throwable $e) {
            @fclose($socket);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    function sendHostingerEmailPhp(array $options): array
    {
        $rawTo = $options['to'] ?? [];
        $toArr = is_array($rawTo) ? $rawTo : [$rawTo];
        $recipients = [];
        foreach ($toArr as $addr) {
            $clean = strtolower(mailerStripCrlfPhp((string) $addr));
            if (isRealDeliverableEmailPhp($clean)) {
                $recipients[] = $clean;
            }
        }
        if (empty($recipients)) {
            return [
                'sent' => false,
                'provider' => 'hostinger_smtp',
                'skipped' => true,
                'error' => 'No valid deliverable recipient email address provided.',
            ];
        }

        $pdo = ($options['pdo'] ?? null) instanceof PDO ? $options['pdo'] : null;
        $override = is_array($options['configOverride'] ?? null) ? $options['configOverride'] : [];
        $cfg = getEffectiveMailConfigPhp($pdo, $override);

        if (empty($cfg['configured'])) {
            return [
                'sent' => false,
                'provider' => 'hostinger_smtp',
                'skipped' => true,
                'reason' => 'Hostinger SMTP is not configured yet. Set MAIL_USERNAME and MAIL_PASSWORD in .env or Admin Panel -> Hostinger Mail.',
            ];
        }

        $fromAddr = $cfg['from_address'];
        $domainPart = str_contains($fromAddr, '@') ? explode('@', $fromAddr, 2)[1] : 'knowlipop.shop';
        $messageId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $domainPart . '>';
        $boundary = '=_Part_' . bin2hex(random_bytes(12));
        $subject = (string) ($options['subject'] ?? 'Notification');
        $htmlBody = (string) ($options['html'] ?? '<p>' . htmlspecialchars((string) ($options['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>');
        $textBody = (string) ($options['text'] ?? trim(preg_replace('/\s+/', ' ', strip_tags($htmlBody))));
        $replyTo = mailerStripCrlfPhp((string) ($options['replyTo'] ?? $cfg['reply_to'] ?? $fromAddr));

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: ' . $messageId,
            'From: ' . formatAddressHeaderPhp($fromAddr, (string) $cfg['from_name']),
            'To: ' . implode(', ', $recipients),
            ...($replyTo !== '' ? ['Reply-To: ' . $replyTo] : []),
            'Subject: ' . encodeMimeHeaderUtf8Php($subject),
            'MIME-Version: 1.0',
            'X-Mailer: Knowlipop-Hostinger-SMTP-PHP/1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $parts = [
            "--{$boundary}",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($textBody !== '' ? $textBody : ' '), 76, "\r\n")),
            "--{$boundary}",
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($htmlBody !== '' ? $htmlBody : ' '), 76, "\r\n")),
            "--{$boundary}--",
            '',
        ];

        $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
        $timeoutSec = (int) ($options['timeoutSec'] ?? 15);
        $res = sendViaSmtpSocketPhp($cfg, $recipients, $fromAddr, $rawMessage, $timeoutSec);

        if (!empty($res['ok'])) {
            return [
                'sent' => true,
                'provider' => 'hostinger_smtp',
                'host' => $cfg['host'],
                'port' => (int) $cfg['port'],
                'from' => $fromAddr,
                'to' => $recipients,
                'message_id' => $messageId,
                'smtp_response' => $res['smtp_response'] ?? 'OK',
            ];
        }

        return [
            'sent' => false,
            'provider' => 'hostinger_smtp',
            'host' => $cfg['host'],
            'port' => (int) $cfg['port'],
            'from' => $fromAddr,
            'to' => $recipients,
            'error' => $res['error'] ?? 'Failed to deliver email via Hostinger SMTP.',
        ];
    }

    function wrapEmailLayoutPhp(string $appName, string $badge, string $title, string $bodyHtml, string $footerNote = ''): string
    {
        $safeApp = htmlspecialchars($appName ?: 'Knowlipop Vendor', ENT_QUOTES, 'UTF-8');
        $safeBadge = htmlspecialchars($badge ?: 'NOTIFICATION', ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeFooter = htmlspecialchars($footerNote ?: "Sent securely via Hostinger Mail for " . ($appName ?: 'Knowlipop Vendor') . ".", ENT_QUOTES, 'UTF-8');

        return "<!doctype html>
<html lang=\"en\">
<head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"></head>
<body style=\"margin:0;padding:24px;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#0f172a;\">
  <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width:580px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 6px 20px rgba(15,23,42,0.06);\">
    <tr>
      <td style=\"background:#0f172a;padding:22px 28px;color:#ffffff;\">
        <div style=\"font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#38bdf8;margin-bottom:6px;\">{$safeApp} &bull; {$safeBadge}</div>
        <h1 style=\"margin:0;font-size:20px;font-weight:700;line-height:1.3;color:#ffffff;\">{$safeTitle}</h1>
      </td>
    </tr>
    <tr>
      <td style=\"padding:26px 28px;font-size:15px;line-height:1.65;color:#334155;\">
        {$bodyHtml}
      </td>
    </tr>
    <tr>
      <td style=\"padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;\">
        {$safeFooter}
      </td>
    </tr>
  </table>
</body>
</html>";
    }

    function sendPasswordResetOtpMailPhp(?PDO $pdo, string $to, string $sellerName, string $otp, int $expiresMinutes = 15): array
    {
        $appName = mailerReadEnvPhp('APP_NAME', 'Knowlipop Vendor');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($sellerName ?: 'Seller', ENT_QUOTES, 'UTF-8');
        $subject = "{$otp} is your {$appName} password reset OTP";
        $bodyHtml = "
          <p style=\"margin:0 0 14px;\">Hello <strong>{$safeName}</strong>,</p>
          <p style=\"margin:0 0 18px;\">Use the 6-digit verification code below to reset your password. This code expires in <strong>{$expiresMinutes} minutes</strong>.</p>
          <div style=\"margin:20px 0;padding:18px;background:#eff6ff;border:1px dashed #3b82f6;border-radius:10px;text-align:center;\">
            <span style=\"font-size:32px;font-weight:800;letter-spacing:8px;color:#1d4ed8;font-family:monospace;\">{$safeOtp}</span>
          </div>
          <p style=\"margin:0;font-size:13px;color:#64748b;\">If you did not request a password reset, you can safely ignore this email.</p>
        ";
        $html = wrapEmailLayoutPhp($appName, 'PASSWORD RESET OTP', 'Reset Your Seller Account Password', $bodyHtml);
        $text = "Hello " . ($sellerName ?: 'Seller') . ",\n\nYour {$appName} password reset OTP is: {$otp}\n\nThis code expires in {$expiresMinutes} minutes.";
        return sendHostingerEmailPhp(['pdo' => $pdo, 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text]);
    }

    function sendWelcomeSellerMailPhp(?PDO $pdo, string $to, string $sellerName, ?string $phone = null): array
    {
        if (isAutomatedPlaceholderDomainPhp($to)) {
            return ['sent' => false, 'skipped' => true];
        }
        $cfg = getEffectiveMailConfigPhp($pdo);
        if (empty($cfg['configured'])) {
            return ['sent' => false, 'skipped' => true];
        }
        $appName = mailerReadEnvPhp('APP_NAME', 'Knowlipop Vendor');
        $safeName = htmlspecialchars($sellerName ?: 'Seller', ENT_QUOTES, 'UTF-8');
        $safeTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
        $phoneRow = $phone ? "<div><strong>Phone:</strong> " . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "</div>" : '';
        $bodyHtml = "
          <p style=\"margin:0 0 14px;\">Hello <strong>{$safeName}</strong>,</p>
          <p style=\"margin:0 0 14px;\">Welcome to <strong>" . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . "</strong>! Your seller account has been registered successfully.</p>
          <div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;\">
            <div><strong>Email:</strong> {$safeTo}</div>
            {$phoneRow}
          </div>
          <p style=\"margin:0;\">You can now upload your GST certificate for verification and start listing products.</p>
        ";
        $html = wrapEmailLayoutPhp($appName, 'SELLER ONBOARDING', 'Your Seller Account Is Ready', $bodyHtml);
        $text = "Hello {$sellerName},\n\nWelcome to {$appName}! Your seller account has been registered.\nEmail: {$to}" . ($phone ? "\nPhone: {$phone}" : '');
        return sendHostingerEmailPhp(['pdo' => $pdo, 'to' => $to, 'subject' => "Welcome to {$appName}, {$sellerName}!", 'html' => $html, 'text' => $text]);
    }

    function sendGstStatusMailPhp(?PDO $pdo, string $to, string $sellerName, string $status, ?string $gstin = '', string $notes = ''): array
    {
        if (isAutomatedPlaceholderDomainPhp($to)) {
            return ['sent' => false, 'skipped' => true];
        }
        $cfg = getEffectiveMailConfigPhp($pdo);
        if (empty($cfg['configured'])) {
            return ['sent' => false, 'skipped' => true];
        }
        $appName = mailerReadEnvPhp('APP_NAME', 'Knowlipop Vendor');
        $isApproved = strtolower($status) === 'approved';
        $subject = $isApproved
            ? "[Approved] Your GST Document Verification — {$appName}"
            : "[Action Required] GST Verification Rejected — {$appName}";
        $color = $isApproved ? '#15803d' : '#b91c1c';
        $gstinRow = $gstin ? "<div><strong>GSTIN:</strong> " . htmlspecialchars($gstin, ENT_QUOTES, 'UTF-8') . "</div>" : '';
        $notesRow = $notes !== '' ? "<div><strong>" . ($isApproved ? 'Admin Notes' : 'Rejection Reason') . ":</strong> " . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') . "</div>" : '';
        $bodyHtml = "
          <p style=\"margin:0 0 14px;\">Hello <strong>" . htmlspecialchars($sellerName ?: 'Seller', ENT_QUOTES, 'UTF-8') . "</strong>,</p>
          <p style=\"margin:0 0 14px;\">Your GST verification status has been updated to <strong style=\"color:{$color};\">" . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . "</strong>.</p>
          <div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;\">
            {$gstinRow}
            {$notesRow}
          </div>
          <p style=\"margin:0;\">" . ($isApproved ? 'Your store is compliant and active.' : 'Please upload a corrected PDF certificate in your seller dashboard.') . "</p>
        ";
        $html = wrapEmailLayoutPhp($appName, $isApproved ? 'GST VERIFIED' : 'GST VERIFICATION UPDATE', $isApproved ? 'GST Verification Approved' : 'GST Verification Rejected', $bodyHtml);
        $text = "Hello " . ($sellerName ?: 'Seller') . ",\nYour GST verification status is now: {$status}." . ($gstin ? "\nGSTIN: {$gstin}" : '') . ($notes !== '' ? "\nDetails: {$notes}" : '');
        return sendHostingerEmailPhp(['pdo' => $pdo, 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text]);
    }

    function sendSellerDeletionLifecycleMailPhp(?PDO $pdo, string $to, string $sellerName, string $event, int $requestId, string $reason = ''): array
    {
        if (isAutomatedPlaceholderDomainPhp($to)) {
            return ['sent' => false, 'skipped' => true];
        }
        $cfg = getEffectiveMailConfigPhp($pdo);
        if (empty($cfg['configured'])) {
            return ['sent' => false, 'skipped' => true];
        }
        $appName = mailerReadEnvPhp('APP_NAME', 'Knowlipop Vendor');
        $evtUpper = strtoupper($event ?: 'UPDATED');
        $titleMap = [
            'REQUESTED' => 'Account Deletion Request Received',
            'APPROVED' => 'Account Deletion Approved',
            'ON_HOLD' => 'Account Deletion Put On Hold',
            'REJECTED' => 'Account Deletion Request Rejected',
            'DELETED' => 'Seller Account Permanently Deleted',
            'WITHDRAWN' => 'Account Deletion Request Withdrawn',
        ];
        $title = $titleMap[$evtUpper] ?? "Account Deletion Update ({$evtUpper})";
        $subject = "[{$appName}] {$title} (#{$requestId})";
        $reasonBlock = $reason !== '' ? "<div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;\"><strong>Details:</strong> " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</div>" : '';
        $bodyHtml = "
          <p style=\"margin:0 0 14px;\">Hello <strong>" . htmlspecialchars($sellerName ?: 'Seller', ENT_QUOTES, 'UTF-8') . "</strong>,</p>
          <p style=\"margin:0 0 14px;\">Update on your seller account deletion request <strong>#{$requestId}</strong>: <strong>" . htmlspecialchars($evtUpper, ENT_QUOTES, 'UTF-8') . "</strong>.</p>
          {$reasonBlock}
          <p style=\"margin:0;font-size:13px;color:#64748b;\">If you have questions regarding orders, refunds, or settlements, please reply to this email.</p>
        ";
        $html = wrapEmailLayoutPhp($appName, 'ACCOUNT DELETION STATUS', $title, $bodyHtml);
        $text = "Hello " . ($sellerName ?: 'Seller') . ",\n\n{$title} (Request #{$requestId})." . ($reason !== '' ? "\nDetails: {$reason}" : '');
        return sendHostingerEmailPhp(['pdo' => $pdo, 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text]);
    }

    function sendTestMailPhp(?PDO $pdo, string $to, array $override = []): array
    {
        $cfg = getEffectiveMailConfigPhp($pdo, $override);
        $appName = mailerReadEnvPhp('APP_NAME', 'Knowlipop Vendor');
        $subject = "Hostinger Mail Test — {$appName}";
        $sentAt = gmdate('c');
        $bodyHtml = "
          <p style=\"margin:0 0 14px;\">This test email confirms your <strong>Hostinger Mail</strong> SMTP integration is working properly.</p>
          <div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;\">
            <div><strong>SMTP Host:</strong> " . htmlspecialchars($cfg['host'], ENT_QUOTES, 'UTF-8') . ":" . (int) $cfg['port'] . " (" . htmlspecialchars(strtoupper($cfg['encryption']), ENT_QUOTES, 'UTF-8') . ")</div>
            <div><strong>From Address:</strong> " . htmlspecialchars($cfg['from_address'], ENT_QUOTES, 'UTF-8') . "</div>
            <div><strong>Sent At:</strong> " . htmlspecialchars($sentAt, ENT_QUOTES, 'UTF-8') . "</div>
          </div>
        ";
        $html = wrapEmailLayoutPhp($appName, 'SMTP CONNECTION TEST', 'Hostinger Business Email Connected!', $bodyHtml);
        $text = "Hostinger Business Email Connected!\nSMTP Host: {$cfg['host']}:{$cfg['port']}\nFrom: {$cfg['from_address']}\nSent At: {$sentAt}";
        return sendHostingerEmailPhp([
            'pdo' => $pdo,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'configOverride' => $override,
        ]);
    }
}

