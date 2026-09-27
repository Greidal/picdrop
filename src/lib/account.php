<?php
/*
 * Account e-mail flows: verification links and password resets.
 * Tokens are random 256-bit values; password reset tokens are stored hashed.
 */

require_once __DIR__ . '/helpers.php';

function hashToken(string $token): string
{
    return hash('sha256', $token);
}

/** True if the address already received the maximum number of account mails in the last hour. */
function isAccountMailThrottled(mysqli $conn, string $email): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM mail_log WHERE email = ? AND sent_at > (NOW() - INTERVAL 1 HOUR)"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['c'] >= ACCOUNT_MAILS_PER_HOUR;
}

function logAccountMail(mysqli $conn, string $email, string $purpose): void
{
    $stmt = $conn->prepare("INSERT INTO mail_log (email, purpose) VALUES (?, ?)");
    $stmt->bind_param("ss", $email, $purpose);
    $stmt->execute();

    if (random_int(1, 50) === 1) {
        $conn->query("DELETE FROM mail_log WHERE sent_at < (NOW() - INTERVAL 1 DAY)");
    }
}

/** Creates a new verification token for the user (replacing any old one) and returns it. */
function issueVerificationToken(mysqli $conn, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $days = VERIFY_TTL_DAYS;
    $stmt = $conn->prepare(
        "UPDATE users SET verify_token = ?, verify_expires_at = NOW() + INTERVAL ? DAY WHERE id = ?"
    );
    $stmt->bind_param("sii", $token, $days, $userId);
    $stmt->execute();
    return $token;
}

/** Creates a single-use password reset token (invalidating older ones) and returns it. */
function issuePasswordResetToken(mysqli $conn, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hashToken($token);
    $minutes = PASSWORD_RESET_TTL_MINUTES;

    $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW()");
    $del->bind_param("i", $userId);
    $del->execute();

    $stmt = $conn->prepare(
        "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ? MINUTE)"
    );
    $stmt->bind_param("isi", $userId, $hash, $minutes);
    $stmt->execute();
    return $token;
}

/** Returns the user (id, email) for a valid, unexpired reset token, or null. */
function findUserByResetToken(mysqli $conn, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $hash = hashToken($token);
    $stmt = $conn->prepare(
        "SELECT u.id, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.expires_at > NOW()"
    );
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Sets a new password and consumes all reset tokens of the user. Following the
 * link proves ownership of the address, so the account also counts as verified.
 */
function resetPassword(mysqli $conn, int $userId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $conn->begin_transaction();

    $upd = $conn->prepare(
        "UPDATE users SET password = ?, is_verified = 1, verify_token = NULL, verify_expires_at = NULL WHERE id = ?"
    );
    $upd->bind_param("si", $hash, $userId);
    $upd->execute();

    $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
    $del->bind_param("i", $userId);
    $del->execute();

    $conn->commit();
}
