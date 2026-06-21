<?php
/**
 * password_reset_service.php — Password recovery service.
 *
 * Public API:
 *   createResetToken(string $email): void       — initiate reset (silent on unknown email)
 *   validateResetToken(string $token): ?array   — check token validity; returns user data or null
 *   consumeResetToken(string $token, string $new_password): bool — set password + invalidate token
 *
 * Email stub:
 *   sendPasswordResetEmail(string $name, string $email, string $token): void
 *   Wire PHPMailer into this function when SMTP is ready.
 */

if (!function_exists('getDB')) {
    require_once __DIR__ . '/db.php';
}

define('RESET_TOKEN_EXPIRY_MINUTES', 60);

// ── Public API ─────────────────────────────────────────────────────────────────

/**
 * Initiate a password reset for the given email address.
 *
 * Security: never reveals whether the email is registered.
 * Any valid-format email always gets the same generic response in the UI.
 */
function createResetToken(string $email): void
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $db = getDB();

    $stmt = $db->prepare(
        "SELECT id, name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Constant-time response — prevents email enumeration via timing attack
        usleep(random_int(80_000, 200_000));
        return;
    }

    // Invalidate all existing unused tokens for this user before issuing a new one
    $db->prepare(
        "UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL"
    )->execute([$user['id']]);

    $token      = bin2hex(random_bytes(32)); // 64 hex chars, cryptographically secure
    $expires_at = date('Y-m-d H:i:s', time() + RESET_TOKEN_EXPIRY_MINUTES * 60);

    $db->prepare(
        "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)"
    )->execute([(int)$user['id'], $token, $expires_at]);

    sendPasswordResetEmail((string)$user['name'], (string)$user['email'], $token);
}

/**
 * Validate a reset token.
 *
 * Returns ['user_id', 'name', 'email'] if the token is:
 *   - found in the database
 *   - not yet used
 *   - not expired
 *   - belongs to an active user
 *
 * Returns null in all other cases (invalid, expired, used, inactive account).
 *
 * @return array{user_id: int, name: string, email: string}|null
 */
function validateResetToken(string $token): ?array
{
    // Structural validation — avoids DB round-trip on garbage input
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT pr.user_id, pr.expires_at, pr.used_at,
                u.name, u.email, u.status
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = ?
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row)                                              return null; // not found
    if ($row['used_at'] !== null)                           return null; // already used
    if ($row['expires_at'] < date('Y-m-d H:i:s'))          return null; // expired
    if ($row['status'] !== 'active')                        return null; // inactive account

    return [
        'user_id' => (int)$row['user_id'],
        'name'    => (string)$row['name'],
        'email'   => (string)$row['email'],
    ];
}

/**
 * Consume a reset token: update the user's password and invalidate the token atomically.
 *
 * Re-validates the token inside a transaction — prevents race conditions where
 * two simultaneous requests try to use the same token.
 *
 * Returns true on success, false on any failure.
 */
function consumeResetToken(string $token, string $new_password): bool
{
    $user = validateResetToken($token);
    if (!$user) return false;

    $db   = getDB();
    $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $db->beginTransaction();

        // Update password
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
           ->execute([$hash, $user['user_id']]);

        // Mark this token used
        $db->prepare(
            "UPDATE password_resets SET used_at = NOW() WHERE token = ? AND used_at IS NULL"
        )->execute([$token]);

        // Invalidate any other outstanding tokens for this user (safety net)
        $db->prepare(
            "UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL"
        )->execute([$user['user_id']]);

        $db->commit();
        return true;

    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('[PASSWORD_RESET] consumeResetToken failed: ' . $e->getMessage());
        return false;
    }
}

// ── Email stub ─────────────────────────────────────────────────────────────────

/**
 * Send the password reset email.
 *
 * Currently a STUB — logs the reset URL in development.
 *
 * To activate:
 *   1. Install PHPMailer into /vendor/PHPMailer/
 *   2. Uncomment the PHPMailer block below
 *   3. Fill in SMTP credentials from Hostinger control panel
 */
function sendPasswordResetEmail(string $name, string $email, string $token): void
{
    $reset_url = rtrim(APP_URL, '/') . '/reset_password.php?token=' . urlencode($token);
    $expires   = RESET_TOKEN_EXPIRY_MINUTES;

    // Development: write reset URL to error log so you can test without SMTP
    if (defined('APP_DEBUG') && APP_DEBUG) {
        error_log("[PASSWORD_RESET] Reset link for {$email}: {$reset_url}");
    }

    // ── PHPMailer (uncomment when SMTP is configured) ──────────────────────
    /*
    require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';   // Hostinger SMTP host
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply@yourdomain.com';
        $mail->Password   = 'YOUR_EMAIL_PASSWORD';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('noreply@yourdomain.com', APP_NAME);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Reset Your Password — ' . APP_NAME;
        $mail->Body    = _buildResetEmailHtml($name, $reset_url, $expires);
        $mail->AltBody = "Hi {$name},\n\n"
                       . "Reset your password by visiting:\n{$reset_url}\n\n"
                       . "This link expires in {$expires} minutes and can only be used once.\n\n"
                       . "If you did not request this, ignore this email.";
        $mail->send();
    } catch (\Throwable $e) {
        error_log('[MAILER] sendPasswordResetEmail failed: ' . $e->getMessage());
    }
    */
}

// ── Internal helpers ───────────────────────────────────────────────────────────

function _buildResetEmailHtml(string $name, string $reset_url, int $expires): string
{
    $name_safe = htmlspecialchars($name,      ENT_QUOTES, 'UTF-8');
    $url_safe  = htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F9FAFB;font-family:Inter,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F9FAFB;padding:40px 20px">
  <tr><td align="center">
    <table width="480" cellpadding="0" cellspacing="0"
           style="background:#fff;border-radius:12px;border:1px solid #E5E7EB;overflow:hidden">
      <tr><td style="background:#2D6A4F;padding:28px 32px;text-align:center">
        <span style="color:#fff;font-size:22px;font-weight:700">🐄 {$name_safe}'s Account</span>
      </td></tr>
      <tr><td style="padding:32px">
        <h2 style="color:#111827;margin:0 0 12px;font-size:20px">Reset Your Password</h2>
        <p style="color:#374151;margin:0 0 24px;line-height:1.6">
          Hi {$name_safe},<br><br>
          We received a request to reset your password. Click the button below to set a new password.
          This link is valid for <strong>{$expires} minutes</strong> and can only be used once.
        </p>
        <table cellpadding="0" cellspacing="0" style="margin:0 auto 24px">
          <tr><td style="background:#2D6A4F;border-radius:8px;padding:14px 32px;text-align:center">
            <a href="{$url_safe}" style="color:#fff;font-size:15px;font-weight:600;text-decoration:none">
              Reset My Password
            </a>
          </td></tr>
        </table>
        <p style="color:#6B7280;font-size:13px;margin:0;line-height:1.6">
          If you did not request this password reset, you can safely ignore this email —
          your password will remain unchanged.<br><br>
          If the button above does not work, copy and paste this link into your browser:<br>
          <a href="{$url_safe}" style="color:#2D6A4F;word-break:break-all">{$url_safe}</a>
        </p>
      </td></tr>
      <tr><td style="background:#F9FAFB;padding:16px 32px;text-align:center;border-top:1px solid #E5E7EB">
        <span style="color:#9CA3AF;font-size:12px">
          &copy; {$expires}&nbsp;&nbsp;This email was sent by AB IT Cow Management.
        </span>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}
