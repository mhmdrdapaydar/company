<?php
declare(strict_types=1);

/** Server-side opaque device tokens. No secrets, token values or passwords are stored in plaintext. */
final class MobileAuth
{
    public const IDLE_SECONDS = 7 * 86400;
    public static function authorizationHeader(): string
    {
        return (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || (defined('MOBILE_TRUST_PROXY') && MOBILE_TRUST_PROXY === true && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }
    public static function requireSecureTransport(): void
    {
        if (!self::isHttps() && !(defined('MOBILE_ALLOW_INSECURE_HTTP') && MOBILE_ALLOW_INSECURE_HTTP === true)) {
            json_error('HTTPS is required.', 403);
        }
    }
    /** Fixed 15-minute windows, keyed by both the normalized account and the direct client IP. */
    public static function consumeLoginAttempt(string $identifier): bool
    {
        $window = intdiv(time(), 900);
        $identity = mb_strtolower(normalize_persian($identifier, true), 'UTF-8');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $limits = [[hash('sha256', 'account:' . $identity), 8], [hash('sha256', 'ip:' . $ip), 100]];
        $allowed = true;
        foreach ($limits as [$key, $max]) {
            if (Database::isMysql()) {
                Database::execute('INSERT INTO mobile_login_limits (limit_key, window_id, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$key, $window]);
            } else {
                Database::execute('INSERT INTO mobile_login_limits (limit_key, window_id, hits) VALUES (?, ?, 1) ON CONFLICT(limit_key, window_id) DO UPDATE SET hits = hits + 1', [$key, $window]);
            }
            $hits = (int) Database::fetchValue('SELECT hits FROM mobile_login_limits WHERE limit_key = ? AND window_id = ?', [$key, $window], 0);
            if ($hits > $max) $allowed = false;
        }
        if (random_int(1, 100) === 1) Database::execute('DELETE FROM mobile_login_limits WHERE window_id < ?', [$window - 2]);
        return $allowed;
    }
    public static function issue(int $userId): string
    {
        $row = Database::fetchOne('SELECT password_hash FROM users WHERE id = ? AND is_active = 1', [$userId]);
        if (!$row) throw new RuntimeException('حساب کاربری فعال نیست.');
        $token = bin2hex(random_bytes(32));
        $now = time();
        if (random_int(1, 100) === 1) Database::execute('DELETE FROM mobile_tokens WHERE last_used_at < ?', [$now - self::IDLE_SECONDS]);
        Database::insert('INSERT INTO mobile_tokens (user_id, token_hash, password_fingerprint, created_at, last_used_at) VALUES (?, ?, ?, ?, ?)', [$userId, hash('sha256', $token), hash('sha256', (string) $row['password_hash']), $now, $now]);
        return $token;
    }
    /** Return the token row ID, never the token itself. Re-check account and password on every request. */
    public static function authenticate(): ?array
    {
        $header = self::authorizationHeader();
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $header, $m)) return null;
        $hash = hash('sha256', $m[1]);
        $row = Database::fetchOne('SELECT t.id, t.user_id, t.password_fingerprint, t.last_used_at, u.password_hash, u.is_active FROM mobile_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?', [$hash]);
        if (!$row || (int) $row['is_active'] !== 1 || (int) $row['last_used_at'] < time() - self::IDLE_SECONDS || !hash_equals((string) $row['password_fingerprint'], hash('sha256', (string) $row['password_hash']))) return null;
        $now = time();
        $updated = Database::execute('UPDATE mobile_tokens SET last_used_at = ? WHERE id = ? AND last_used_at >= ?', [$now, (int) $row['id'], $now - self::IDLE_SECONDS]);
        // MySQL may report zero changed rows if another request updated it in the same second.
        if (!$updated && !Database::fetchOne('SELECT id FROM mobile_tokens WHERE id = ? AND last_used_at >= ?', [(int) $row['id'], $now - self::IDLE_SECONDS])) return null;
        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
    }
    public static function revoke(int $tokenId): void
    {
        Database::execute('DELETE FROM mobile_tokens WHERE id = ?', [$tokenId]);
    }
    public static function recordPreview(int $tokenId, string $file): void
    {
        Database::execute('DELETE FROM mobile_voice_previews WHERE token_id = ? AND created_at <= ?', [$tokenId, time() - 86400]);
        Database::insert('INSERT INTO mobile_voice_previews (token_id, file_name, created_at) VALUES (?, ?, ?)', [$tokenId, $file, time()]);
    }
    public static function ownsPreview(int $tokenId, string $file): bool
    {
        return (bool) Database::fetchOne('SELECT token_id FROM mobile_voice_previews WHERE token_id = ? AND file_name = ? AND created_at > ?', [$tokenId, $file, time() - 86400]);
    }
}
