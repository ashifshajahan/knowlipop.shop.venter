/**
 * Hostinger Business Email & SMTP Mailer (Zero-Dependency Node.js)
 * Supports Hostinger Mail (smtp.hostinger.com:465 SSL / 587 STARTTLS),
 * MySQL `mail_settings` runtime persistence, .env fallback, and transactional templates.
 */

'use strict';

const tls = require('tls');
const net = require('net');
const crypto = require('crypto');

const DEFAULT_SMTP_HOST = 'smtp.hostinger.com';
const DEFAULT_SMTP_PORT = 465;

let mailSchemaEnsured = false;
let fallbackMailSettings = null;

function stripCrlf(val) {
  return String(val || '').replace(/[\r\n]+/g, ' ').trim();
}

function isRealDeliverableEmailNode(email) {
  if (!email || typeof email !== 'string') return false;
  const trimmed = email.trim().toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(trimmed)) return false;
  if (trimmed.endsWith('@phone.vendor.local')) return false;
  return true;
}

function isAutomatedPlaceholderDomainNode(email) {
  const lower = String(email || '').trim().toLowerCase();
  return (
    lower.endsWith('@phone.vendor.local') ||
    lower.endsWith('@example.com') ||
    lower.endsWith('@php.test') ||
    lower.endsWith('@vendor.app') ||
    lower.endsWith('.test') ||
    lower.endsWith('.local')
  );
}

function encodeMimeHeaderUtf8(str) {
  const clean = stripCrlf(str);
  if (!clean) return '';
  if (/^[\x20-\x7E]*$/.test(clean)) {
    return clean;
  }
  return `=?UTF-8?B?${Buffer.from(clean, 'utf8').toString('base64')}?=`;
}

function formatAddressHeader(address, displayName = '') {
  const cleanAddr = stripCrlf(address);
  const cleanName = stripCrlf(displayName).replace(/"/g, '');
  if (!cleanName) return cleanAddr;
  if (/^[\x20-\x7E]*$/.test(cleanName)) {
    return `"${cleanName}" <${cleanAddr}>`;
  }
  return `${encodeMimeHeaderUtf8(cleanName)} <${cleanAddr}>`;
}

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function normalizeMailConfig(raw = {}) {
  const host = stripCrlf(raw.host || raw.smtp_host || process.env.MAIL_HOST || DEFAULT_SMTP_HOST) || DEFAULT_SMTP_HOST;
  const portRaw = raw.port ?? raw.smtp_port ?? process.env.MAIL_PORT ?? DEFAULT_SMTP_PORT;
  const port = Number.parseInt(portRaw, 10) || DEFAULT_SMTP_PORT;
  let encryption = String(raw.encryption || raw.smtp_encryption || process.env.MAIL_ENCRYPTION || '').trim().toLowerCase();
  if (!encryption) {
    encryption = port === 465 ? 'ssl' : (port === 587 ? 'tls' : 'ssl');
  }
  const username = stripCrlf(raw.username ?? raw.smtp_username ?? process.env.MAIL_USERNAME ?? '');
  const password = String(raw.password ?? raw.smtp_password ?? process.env.MAIL_PASSWORD ?? '');
  const fromAddress = stripCrlf(raw.from_address ?? raw.fromAddress ?? process.env.MAIL_FROM_ADDRESS ?? '') || username;
  const fromName = stripCrlf(raw.from_name ?? raw.fromName ?? process.env.MAIL_FROM_NAME ?? process.env.APP_NAME ?? 'Knowlipop Vendor') || 'Knowlipop Vendor';
  const replyTo = stripCrlf(raw.reply_to ?? raw.replyTo ?? process.env.MAIL_REPLY_TO ?? '') || fromAddress;
  const enabledFlag = raw.is_active !== undefined ? Boolean(Number(raw.is_active)) : true;
  const configured = Boolean(enabledFlag && host && port > 0 && username && password && fromAddress);

  return {
    mailer: stripCrlf(raw.mailer || process.env.MAIL_MAILER || 'smtp').toLowerCase() || 'smtp',
    host,
    port,
    encryption,
    username,
    password,
    from_address: fromAddress,
    from_name: fromName,
    reply_to: replyTo,
    is_active: enabledFlag,
    configured,
    source: raw._source || 'env'
  };
}

async function ensureMailSettingsSchemaNode(pool) {
  if (mailSchemaEnsured || !pool) return;
  try {
    await pool.query(`
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
    `);
    mailSchemaEnsured = true;
  } catch (err) {
    // Do not throw on read-only fallback mode
  }
}

async function getEffectiveMailConfigNode(pool = null, overrideCfg = null) {
  const envBase = normalizeMailConfig({ _source: 'env' });

  let dbRow = null;
  if (pool) {
    try {
      await ensureMailSettingsSchemaNode(pool);
      const [rows] = await pool.query('SELECT * FROM mail_settings WHERE id = 1 LIMIT 1');
      if (rows && rows.length > 0) {
        dbRow = rows[0];
      }
    } catch (err) {
      // Fall back cleanly if DB unreachable
    }
  } else if (fallbackMailSettings) {
    dbRow = fallbackMailSettings;
  }

  let base = envBase;
  if (dbRow && Number(dbRow.is_active ?? 1) === 1 && (dbRow.username || dbRow.password)) {
    base = normalizeMailConfig({
      mailer: dbRow.mailer || envBase.mailer,
      host: dbRow.host || envBase.host,
      port: dbRow.port || envBase.port,
      encryption: dbRow.encryption || envBase.encryption,
      username: dbRow.username || envBase.username,
      password: dbRow.password || envBase.password,
      from_address: dbRow.from_address || dbRow.username || envBase.from_address,
      from_name: dbRow.from_name || envBase.from_name,
      reply_to: dbRow.reply_to || dbRow.from_address || envBase.reply_to,
      is_active: dbRow.is_active,
      _source: 'database'
    });
  }

  if (overrideCfg && typeof overrideCfg === 'object' && Object.keys(overrideCfg).length > 0) {
    const merged = {
      ...base,
      ...overrideCfg,
      password: (overrideCfg.password !== undefined && String(overrideCfg.password) !== '')
        ? overrideCfg.password
        : base.password,
      _source: 'override'
    };
    return normalizeMailConfig(merged);
  }

  return base;
}

async function getPublicMailConfigSummaryNode(pool = null) {
  const cfg = await getEffectiveMailConfigNode(pool);
  return {
    provider: 'hostinger_smtp',
    mailer: cfg.mailer,
    host: cfg.host,
    port: cfg.port,
    encryption: cfg.encryption,
    username: cfg.username || '',
    has_password: Boolean(cfg.password),
    from_address: cfg.from_address || '',
    from_name: cfg.from_name || 'Knowlipop Vendor',
    reply_to: cfg.reply_to || '',
    is_active: Boolean(cfg.is_active),
    configured: Boolean(cfg.configured),
    source: cfg.configured ? cfg.source : 'unconfigured'
  };
}

async function saveMailConfigNode(pool = null, payload = {}, updatedBy = null) {
  const existing = await getEffectiveMailConfigNode(pool);
  const host = stripCrlf(payload.host || existing.host || DEFAULT_SMTP_HOST) || DEFAULT_SMTP_HOST;
  const port = Number.parseInt(payload.port ?? existing.port ?? DEFAULT_SMTP_PORT, 10) || DEFAULT_SMTP_PORT;
  const encryption = stripCrlf(payload.encryption || (port === 465 ? 'ssl' : 'tls')).toLowerCase();
  const username = stripCrlf(payload.username !== undefined ? payload.username : existing.username);
  const nextPassword = (payload.password !== undefined && String(payload.password) !== '')
    ? String(payload.password)
    : existing.password;
  const fromAddress = stripCrlf(payload.from_address || username || existing.from_address);
  const fromName = stripCrlf(payload.from_name || existing.from_name || 'Knowlipop Vendor') || 'Knowlipop Vendor';
  const replyTo = stripCrlf(payload.reply_to || fromAddress || existing.reply_to);
  const isActive = payload.is_active === undefined ? 1 : (payload.is_active ? 1 : 0);

  if (pool) {
    await ensureMailSettingsSchemaNode(pool);
    await pool.query(
      `INSERT INTO mail_settings (id, mailer, host, port, encryption, username, password, from_address, from_name, reply_to, is_active, updated_by, created_at, updated_at)
       VALUES (1, 'smtp', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
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
         updated_at = NOW()`,
      [host, port, encryption, username, nextPassword, fromAddress, fromName, replyTo, isActive, updatedBy]
    );
  } else {
    fallbackMailSettings = {
      id: 1,
      mailer: 'smtp',
      host,
      port,
      encryption,
      username,
      password: nextPassword,
      from_address: fromAddress,
      from_name: fromName,
      reply_to: replyTo,
      is_active: isActive,
      updated_by: updatedBy
    };
  }

  return getPublicMailConfigSummaryNode(pool);
}

function buildMimeMessage({ fromAddress, fromName, replyTo, toList, subject, textBody, htmlBody }) {
  const domainPart = (fromAddress && fromAddress.includes('@')) ? fromAddress.split('@')[1] : 'knowlipop.shop';
  const messageId = `<${Date.now()}.${crypto.randomBytes(8).toString('hex')}@${domainPart}>`;
  const boundary = `=_Part_${crypto.randomBytes(12).toString('hex')}`;

  const cleanText = String(textBody || '').replace(/\r?\n/g, '\r\n');
  const cleanHtml = String(htmlBody || '').replace(/\r?\n/g, '\r\n');

  const headers = [
    `Date: ${new Date().toUTCString()}`,
    `Message-ID: ${messageId}`,
    `From: ${formatAddressHeader(fromAddress, fromName)}`,
    `To: ${toList.map(t => stripCrlf(t)).join(', ')}`,
    ...(replyTo ? [`Reply-To: ${stripCrlf(replyTo)}`] : []),
    `Subject: ${encodeMimeHeaderUtf8(subject)}`,
    `MIME-Version: 1.0`,
    `X-Mailer: Knowlipop-Hostinger-SMTP/1.0`,
    `Content-Type: multipart/alternative; boundary="${boundary}"`
  ];

  const parts = [
    `--${boundary}`,
    `Content-Type: text/plain; charset=UTF-8`,
    `Content-Transfer-Encoding: base64`,
    ``,
    Buffer.from(cleanText || ' ', 'utf8').toString('base64').replace(/.{1,76}/g, '$&\r\n').trimEnd(),
    `--${boundary}`,
    `Content-Type: text/html; charset=UTF-8`,
    `Content-Transfer-Encoding: base64`,
    ``,
    Buffer.from(cleanHtml || cleanText || ' ', 'utf8').toString('base64').replace(/.{1,76}/g, '$&\r\n').trimEnd(),
    `--${boundary}--`,
    ``
  ];

  return {
    messageId,
    raw: headers.join('\r\n') + '\r\n\r\n' + parts.join('\r\n')
  };
}

function sendViaSmtpSocket(cfg, { toList, fromAddress, rawMessage, timeoutMs = 15000 }) {
  return new Promise((resolve) => {
    let settled = false;
    let socket = null;
    let buffer = '';
    let currentWaiter = null;

    const finish = (result) => {
      if (settled) return;
      settled = true;
      if (currentWaiter) {
        const w = currentWaiter;
        currentWaiter = null;
        clearTimeout(w.timer);
      }
      try {
        if (socket && !socket.destroyed) socket.destroy();
      } catch (e) {}
      resolve(result);
    };

    const attachDataListener = (sock) => {
      sock.on('data', (chunk) => {
        buffer += chunk.toString('utf8');
        while (currentWaiter) {
          const lines = buffer.split(/\r?\n/);
          let foundIdx = -1;
          let parsedCode = 0;
          let fullReply = [];
          for (let i = 0; i < lines.length - 1; i++) {
            const line = lines[i];
            if (!line) continue;
            fullReply.push(line);
            const m = /^(\d{3})([ -])(.*)$/.exec(line);
            if (m && m[2] === ' ') {
              foundIdx = i;
              parsedCode = Number.parseInt(m[1], 10);
              break;
            }
          }
          if (foundIdx === -1) break;
          buffer = lines.slice(foundIdx + 1).join('\r\n');
          const waiter = currentWaiter;
          currentWaiter = null;
          clearTimeout(waiter.timer);
          waiter.resolve({ code: parsedCode, text: fullReply.join('\n') });
        }
      });

      sock.on('error', (err) => {
        if (currentWaiter) {
          const w = currentWaiter;
          currentWaiter = null;
          clearTimeout(w.timer);
          w.reject(err);
        } else {
          finish({ ok: false, error: `SMTP socket error: ${err.message}` });
        }
      });

      sock.on('close', () => {
        if (!settled && currentWaiter) {
          const w = currentWaiter;
          currentWaiter = null;
          clearTimeout(w.timer);
          w.reject(new Error('SMTP connection closed unexpectedly.'));
        }
      });
    };

    const readReply = () => new Promise((resWait, rejWait) => {
      const timer = setTimeout(() => {
        currentWaiter = null;
        rejWait(new Error('SMTP server response timeout.'));
      }, timeoutMs);
      currentWaiter = { resolve: resWait, reject: rejWait, timer };
    });

    const sendCommand = async (cmd, expectedCodes) => {
      const waitPromise = readReply();
      socket.write(cmd + '\r\n');
      const reply = await waitPromise;
      if (!expectedCodes.includes(reply.code)) {
        throw new Error(`SMTP error on "${cmd.split(' ')[0]}": [${reply.code}] ${reply.text}`);
      }
      return reply;
    };

    const runSmtpSession = async () => {
      const greet = await readReply();
      if (greet.code !== 220) {
        throw new Error(`Unexpected SMTP greeting [${greet.code}]: ${greet.text}`);
      }

      const ehloDomain = (fromAddress && fromAddress.includes('@')) ? fromAddress.split('@')[1] : 'knowlipop.shop';
      let ehloReply = await sendCommand(`EHLO ${ehloDomain}`, [250]);

      const enc = String(cfg.encryption || '').toLowerCase();
      if (enc === 'tls' || enc === 'starttls' || (cfg.port === 587 && enc !== 'none' && enc !== 'ssl')) {
        await sendCommand('STARTTLS', [220]);
        socket = await new Promise((resUpgrade, rejUpgrade) => {
          const tlsSock = tls.connect({
            socket,
            servername: cfg.host,
            rejectUnauthorized: cfg.rejectUnauthorized !== undefined ? Boolean(cfg.rejectUnauthorized) : true
          }, () => resUpgrade(tlsSock));
          tlsSock.once('error', rejUpgrade);
        });
        buffer = '';
        attachDataListener(socket);
        ehloReply = await sendCommand(`EHLO ${ehloDomain}`, [250]);
      }

      const supportsPlainOnly = /AUTH\b[^\n]*\bPLAIN\b/i.test(ehloReply.text) && !/AUTH\b[^\n]*\bLOGIN\b/i.test(ehloReply.text);
      if (supportsPlainOnly) {
        const plainToken = Buffer.from(`\0${cfg.username}\0${cfg.password}`, 'utf8').toString('base64');
        await sendCommand(`AUTH PLAIN ${plainToken}`, [235]);
      } else {
        await sendCommand('AUTH LOGIN', [334]);
        await sendCommand(Buffer.from(cfg.username, 'utf8').toString('base64'), [334]);
        await sendCommand(Buffer.from(cfg.password, 'utf8').toString('base64'), [235]);
      }

      await sendCommand(`MAIL FROM:<${stripCrlf(fromAddress)}>`, [250]);
      for (const rcpt of toList) {
        await sendCommand(`RCPT TO:<${stripCrlf(rcpt)}>`, [250, 251]);
      }

      await sendCommand('DATA', [354]);

      // RFC 5321 dot-stuffing for lines beginning with '.'
      const stuffedBody = rawMessage.replace(/^\./gm, '..');
      const dataWait = readReply();
      socket.write(stuffedBody + '\r\n.\r\n');
      const dataReply = await dataWait;
      if (dataReply.code !== 250) {
        throw new Error(`SMTP DATA rejected [${dataReply.code}]: ${dataReply.text}`);
      }

      try {
        await sendCommand('QUIT', [221, 250]);
      } catch (ignore) {}

      finish({ ok: true, smtp_response: dataReply.text });
    };

    try {
      const useDirectSsl = (cfg.encryption === 'ssl' || (cfg.port === 465 && cfg.encryption !== 'tls' && cfg.encryption !== 'none'));
      if (useDirectSsl) {
        socket = tls.connect({
          host: cfg.host,
          port: cfg.port,
          servername: cfg.host,
          rejectUnauthorized: cfg.rejectUnauthorized !== undefined ? Boolean(cfg.rejectUnauthorized) : true
        });
      } else {
        socket = net.connect({ host: cfg.host, port: cfg.port });
      }
      socket.setTimeout(timeoutMs, () => {
        finish({ ok: false, error: `SMTP socket timed out after ${timeoutMs}ms connecting to ${cfg.host}:${cfg.port}` });
      });
      attachDataListener(socket);
      runSmtpSession().catch((err) => {
        finish({ ok: false, error: err.message });
      });
    } catch (err) {
      finish({ ok: false, error: err.message });
    }
  });
}

async function sendHostingerEmailNode({
  to,
  subject,
  html,
  text,
  replyTo = null,
  pool = null,
  configOverride = null,
  timeoutMs = 15000
} = {}) {
  const recipients = (Array.isArray(to) ? to : [to])
    .map(v => stripCrlf(v).toLowerCase())
    .filter(v => isRealDeliverableEmailNode(v));

  if (recipients.length === 0) {
    return {
      sent: false,
      provider: 'hostinger_smtp',
      skipped: true,
      error: 'No valid deliverable recipient email address provided.'
    };
  }

  const cfg = await getEffectiveMailConfigNode(pool, configOverride);
  if (!cfg.configured) {
    return {
      sent: false,
      provider: 'hostinger_smtp',
      skipped: true,
      reason: 'Hostinger SMTP is not configured yet. Set MAIL_USERNAME and MAIL_PASSWORD in .env or Admin Panel -> Hostinger Mail.'
    };
  }

  const { messageId, raw } = buildMimeMessage({
    fromAddress: cfg.from_address,
    fromName: cfg.from_name,
    replyTo: replyTo || cfg.reply_to || cfg.from_address,
    toList: recipients,
    subject: subject || 'Notification',
    textBody: text || String(html || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim(),
    htmlBody: html || `<p>${escapeHtml(text || '')}</p>`
  });

  const result = await sendViaSmtpSocket(cfg, {
    toList: recipients,
    fromAddress: cfg.from_address,
    rawMessage: raw,
    timeoutMs
  });

  if (result.ok) {
    return {
      sent: true,
      provider: 'hostinger_smtp',
      host: cfg.host,
      port: cfg.port,
      from: cfg.from_address,
      to: recipients,
      message_id: messageId,
      smtp_response: result.smtp_response
    };
  }

  return {
    sent: false,
    provider: 'hostinger_smtp',
    host: cfg.host,
    port: cfg.port,
    from: cfg.from_address,
    to: recipients,
    error: result.error || 'Failed to deliver email via Hostinger SMTP.'
  };
}

function wrapEmailLayout({ appName, badge, title, bodyHtml, footerNote }) {
  const safeApp = escapeHtml(appName || 'Knowlipop Vendor');
  const safeBadge = escapeHtml(badge || 'NOTIFICATION');
  const safeTitle = escapeHtml(title || '');
  const safeFooter = escapeHtml(footerNote || `Sent securely via Hostinger Mail for ${appName || 'Knowlipop Vendor'}.`);

  return `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#0f172a;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:580px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 6px 20px rgba(15,23,42,0.06);">
    <tr>
      <td style="background:#0f172a;padding:22px 28px;color:#ffffff;">
        <div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#38bdf8;margin-bottom:6px;">${safeApp} &bull; ${safeBadge}</div>
        <h1 style="margin:0;font-size:20px;font-weight:700;line-height:1.3;color:#ffffff;">${safeTitle}</h1>
      </td>
    </tr>
    <tr>
      <td style="padding:26px 28px;font-size:15px;line-height:1.65;color:#334155;">
        ${bodyHtml}
      </td>
    </tr>
    <tr>
      <td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
        ${safeFooter}
      </td>
    </tr>
  </table>
</body>
</html>`;
}

async function sendPasswordResetOtpMailNode(pool, { to, sellerName = 'Seller', otp, expiresMinutes = 15, configOverride = null } = {}) {
  const appName = process.env.APP_NAME || 'Knowlipop Vendor';
  const safeOtp = escapeHtml(otp);
  const safeName = escapeHtml(sellerName || 'Seller');
  const subject = `${otp} is your ${appName} password reset OTP`;
  const html = wrapEmailLayout({
    appName,
    badge: 'PASSWORD RESET OTP',
    title: 'Reset Your Seller Account Password',
    bodyHtml: `
      <p style="margin:0 0 14px;">Hello <strong>${safeName}</strong>,</p>
      <p style="margin:0 0 18px;">Use the 6-digit verification code below to reset your password. This code expires in <strong>${expiresMinutes} minutes</strong>.</p>
      <div style="margin:20px 0;padding:18px;background:#eff6ff;border:1px dashed #3b82f6;border-radius:10px;text-align:center;">
        <span style="font-size:32px;font-weight:800;letter-spacing:8px;color:#1d4ed8;font-family:monospace;">${safeOtp}</span>
      </div>
      <p style="margin:0;font-size:13px;color:#64748b;">If you did not request a password reset, you can safely ignore this email.</p>
    `
  });
  const text = `Hello ${sellerName || 'Seller'},\n\nYour ${appName} password reset OTP is: ${otp}\n\nThis code expires in ${expiresMinutes} minutes. If you did not request this, please ignore this email.`;
  return sendHostingerEmailNode({ to, subject, html, text, pool, configOverride });
}

async function sendWelcomeSellerMailNode(pool, { to, sellerName = 'Seller', phone = null } = {}) {
  if (isAutomatedPlaceholderDomainNode(to)) return { sent: false, skipped: true };
  const cfg = await getEffectiveMailConfigNode(pool);
  if (!cfg.configured) return { sent: false, skipped: true };

  const appName = process.env.APP_NAME || 'Knowlipop Vendor';
  const subject = `Welcome to ${appName}, ${sellerName}!`;
  const html = wrapEmailLayout({
    appName,
    badge: 'SELLER ONBOARDING',
    title: 'Your Seller Account Is Ready',
    bodyHtml: `
      <p style="margin:0 0 14px;">Hello <strong>${escapeHtml(sellerName)}</strong>,</p>
      <p style="margin:0 0 14px;">Welcome to <strong>${escapeHtml(appName)}</strong>! Your seller account has been registered successfully.</p>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;">
        <div><strong>Email:</strong> ${escapeHtml(to)}</div>
        ${phone ? `<div><strong>Phone:</strong> ${escapeHtml(phone)}</div>` : ''}
      </div>
      <p style="margin:0;">You can now upload your GST certificate for verification and start listing products.</p>
    `
  });
  const text = `Hello ${sellerName},\n\nWelcome to ${appName}! Your seller account has been registered.\nEmail: ${to}${phone ? `\nPhone: ${phone}` : ''}`;
  return sendHostingerEmailNode({ to, subject, html, text, pool });
}

async function sendGstStatusMailNode(pool, { to, sellerName = 'Seller', status = 'Approved', gstin = '', notes = '' } = {}) {
  if (isAutomatedPlaceholderDomainNode(to)) return { sent: false, skipped: true };
  const cfg = await getEffectiveMailConfigNode(pool);
  if (!cfg.configured) return { sent: false, skipped: true };

  const appName = process.env.APP_NAME || 'Knowlipop Vendor';
  const isApproved = String(status).toLowerCase() === 'approved';
  const subject = isApproved
    ? `[Approved] Your GST Document Verification — ${appName}`
    : `[Action Required] GST Verification Rejected — ${appName}`;
  const html = wrapEmailLayout({
    appName,
    badge: isApproved ? 'GST VERIFIED' : 'GST VERIFICATION UPDATE',
    title: isApproved ? 'GST Verification Approved' : 'GST Verification Rejected',
    bodyHtml: `
      <p style="margin:0 0 14px;">Hello <strong>${escapeHtml(sellerName || 'Seller')}</strong>,</p>
      <p style="margin:0 0 14px;">Your GST verification status has been updated to <strong style="color:${isApproved ? '#15803d' : '#b91c1c'};">${escapeHtml(status)}</strong>.</p>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;">
        ${gstin ? `<div><strong>GSTIN:</strong> ${escapeHtml(gstin)}</div>` : ''}
        ${notes ? `<div><strong>${isApproved ? 'Admin Notes' : 'Rejection Reason'}:</strong> ${escapeHtml(notes)}</div>` : ''}
      </div>
      <p style="margin:0;">${isApproved ? 'Your store is compliant and active.' : 'Please upload a corrected PDF certificate in your seller dashboard.'}</p>
    `
  });
  const text = `Hello ${sellerName || 'Seller'},\nYour GST verification status is now: ${status}.${gstin ? `\nGSTIN: ${gstin}` : ''}${notes ? `\nDetails: ${notes}` : ''}`;
  return sendHostingerEmailNode({ to, subject, html, text, pool });
}

async function sendSellerDeletionLifecycleMailNode(pool, { to, sellerName = 'Seller', event = 'UPDATED', requestId = 0, reason = '' } = {}) {
  if (isAutomatedPlaceholderDomainNode(to)) return { sent: false, skipped: true };
  const cfg = await getEffectiveMailConfigNode(pool);
  if (!cfg.configured) return { sent: false, skipped: true };

  const appName = process.env.APP_NAME || 'Knowlipop Vendor';
  const evtUpper = String(event || 'UPDATED').toUpperCase();
  const titleMap = {
    REQUESTED: 'Account Deletion Request Received',
    APPROVED: 'Account Deletion Approved',
    ON_HOLD: 'Account Deletion Put On Hold',
    REJECTED: 'Account Deletion Request Rejected',
    DELETED: 'Seller Account Permanently Deleted',
    WITHDRAWN: 'Account Deletion Request Withdrawn'
  };
  const title = titleMap[evtUpper] || `Account Deletion Update (${evtUpper})`;
  const subject = `[${appName}] ${title} (#${requestId})`;
  const html = wrapEmailLayout({
    appName,
    badge: 'ACCOUNT DELETION STATUS',
    title,
    bodyHtml: `
      <p style="margin:0 0 14px;">Hello <strong>${escapeHtml(sellerName || 'Seller')}</strong>,</p>
      <p style="margin:0 0 14px;">Update on your seller account deletion request <strong>#${Number(requestId)}</strong>: <strong>${escapeHtml(evtUpper)}</strong>.</p>
      ${reason ? `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;"><strong>Details:</strong> ${escapeHtml(reason)}</div>` : ''}
      <p style="margin:0;font-size:13px;color:#64748b;">If you have questions regarding orders, refunds, or settlements, please reply to this email.</p>
    `
  });
  const text = `Hello ${sellerName || 'Seller'},\n\n${title} (Request #${requestId}).${reason ? `\nDetails: ${reason}` : ''}`;
  return sendHostingerEmailNode({ to, subject, html, text, pool });
}

async function sendTestMailNode(pool, { to, configOverride = null } = {}) {
  const cfg = await getEffectiveMailConfigNode(pool, configOverride);
  const appName = process.env.APP_NAME || 'Knowlipop Vendor';
  const subject = `Hostinger Mail Test — ${appName}`;
  const html = wrapEmailLayout({
    appName,
    badge: 'SMTP CONNECTION TEST',
    title: 'Hostinger Business Email Connected!',
    bodyHtml: `
      <p style="margin:0 0 14px;">This test email confirms your <strong>Hostinger Mail</strong> SMTP integration is working properly.</p>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:16px 0;font-size:14px;">
        <div><strong>SMTP Host:</strong> ${escapeHtml(cfg.host)}:${Number(cfg.port)} (${escapeHtml(cfg.encryption.toUpperCase())})</div>
        <div><strong>From Address:</strong> ${escapeHtml(cfg.from_address)}</div>
        <div><strong>Sent At:</strong> ${escapeHtml(new Date().toISOString())}</div>
      </div>
    `
  });
  const text = `Hostinger Business Email Connected!\nSMTP Host: ${cfg.host}:${cfg.port}\nFrom: ${cfg.from_address}\nSent At: ${new Date().toISOString()}`;
  return sendHostingerEmailNode({ to, subject, html, text, pool, configOverride });
}

module.exports = {
  DEFAULT_SMTP_HOST,
  DEFAULT_SMTP_PORT,
  escapeHtml,
  wrapEmailLayout,
  isRealDeliverableEmailNode,
  isAutomatedPlaceholderDomainNode,
  ensureMailSettingsSchemaNode,
  getEffectiveMailConfigNode,
  getPublicMailConfigSummaryNode,
  saveMailConfigNode,
  sendHostingerEmailNode,
  sendPasswordResetOtpMailNode,
  sendWelcomeSellerMailNode,
  sendGstStatusMailNode,
  sendSellerDeletionLifecycleMailNode,
  sendTestMailNode
};
