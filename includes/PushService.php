<?php
declare(strict_types=1);

/** Optional Web Push. In-app notifications remain independent of this service. */
final class PushService
{
    public static function enabled(): bool
    {
        return filter_var(app_env('WEB_PUSH_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)
            && (bool) preg_match('/^B[A-Za-z0-9_-]{86}$/', (string) app_env('WEB_PUSH_PUBLIC_KEY', ''))
            && strlen((string) app_env('WEB_PUSH_WORKER_TOKEN', '')) >= 32;
    }
    public static function publicKey(): string { return (string) app_env('WEB_PUSH_PUBLIC_KEY', ''); }
    public static function allowedEndpoint(string $url): bool
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        $p = parse_url($url);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)) return false;
        $host = strtolower((string) ($p['host'] ?? ''));
        return $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com'
            || (bool) preg_match('/^(?:[a-z0-9-]+\.)+(?:push\.apple\.com|notify\.windows\.com|wns\.windows\.com)$/D', $host);
    }
    private static function validKey(string $key, int $length): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $key)) return false;
        $decoded = base64_decode(strtr($key, '-_', '+/') . str_repeat('=', (4 - strlen($key) % 4) % 4), true);
        return is_string($decoded) && strlen($decoded) === $length && ($length !== 65 || $decoded[0] === "\x04");
    }
    public static function subscribe(int $userId, array $data): void
    {
        if (!self::enabled()) throw new RuntimeException('ارسال پوش در سرور پیکربندی نشده است.');
        $endpoint = is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';
        $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $p256 = is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '';
        $auth = is_string($keys['auth'] ?? null) ? $keys['auth'] : '';
        if (!self::allowedEndpoint($endpoint) || !self::validKey($p256, 65) || !self::validKey($auth, 16)) throw new RuntimeException('اشتراک اعلان نامعتبر است.');
        $hash = hash('sha256', $endpoint);
        Database::transaction(function () use ($userId, $endpoint, $p256, $auth, $hash): void {
            $existing = Database::fetchOne('SELECT id, user_id FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);
            if ($existing) {
                if ((int) $existing['user_id'] !== $userId && (int) Database::fetchValue('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$userId], 0) >= 10) throw new RuntimeException('حداکثر تعداد دستگاه‌های اعلان ثبت شده است.');
                if ((int) $existing['user_id'] !== $userId) Database::execute('DELETE FROM push_jobs WHERE subscription_id = ?', [(int) $existing['id']]);
                Database::execute('UPDATE push_subscriptions SET user_id = ?, endpoint = ?, p256dh = ?, auth_secret = ?, updated_at = ? WHERE id = ?', [$userId, $endpoint, $p256, $auth, now_datetime(), (int) $existing['id']]);
                return;
            }
            $count = (int) Database::fetchValue('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$userId], 0);
            if ($count >= 10) throw new RuntimeException('حداکثر تعداد دستگاه‌های اعلان ثبت شده است.');
            Database::insert('INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_secret, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$userId, $hash, $endpoint, $p256, $auth, now_datetime(), now_datetime()]);
        });
    }
    public static function unsubscribe(int $userId, string $endpoint): void
    {
        if (strlen($endpoint) > 2048) throw new RuntimeException('اشتراک نامعتبر است.');
        Database::execute('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
    }
    public static function enqueue(int $notificationId, int $userId): void
    {
        if (!self::enabled()) return;
        $now = now_datetime();
        Database::execute('INSERT INTO push_jobs (notification_id, subscription_id, attempts, available_at, created_at) SELECT ?, id, 0, ?, ? FROM push_subscriptions WHERE user_id = ?', [$notificationId, $now, $now, $userId]);
    }
    public static function verifyWorker(string $token): bool
    {
        $expected = (string) app_env('WEB_PUSH_WORKER_TOKEN', '');
        return self::enabled() && strlen($expected) >= 32 && hash_equals($expected, $token);
    }
    public static function claim(): ?array
    {
        $now = now_datetime();
        // Expired jobs and notifications already read need no device alert.
        Database::execute('DELETE FROM push_jobs WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
        Database::execute('DELETE FROM push_jobs WHERE notification_id IN (SELECT id FROM notifications WHERE is_read = 1)');
        $candidates = Database::fetchAll('SELECT j.id FROM push_jobs j JOIN notifications n ON n.id = j.notification_id WHERE j.available_at <= ? AND n.is_read = 0 ORDER BY j.id ASC LIMIT 20', [$now]);
        foreach ($candidates as $item) {
            $lease = bin2hex(random_bytes(24));
            $updated = Database::execute('UPDATE push_jobs SET lease_token = ?, attempts = attempts + 1, available_at = ? WHERE id = ? AND available_at <= ?', [$lease, date('Y-m-d H:i:s', time() + 120), (int) $item['id'], $now]);
            if (!$updated) continue;
            $row = Database::fetchOne('SELECT j.id, j.notification_id, j.attempts, s.endpoint, s.p256dh, s.auth_secret FROM push_jobs j JOIN push_subscriptions s ON s.id = j.subscription_id JOIN notifications n ON n.id = j.notification_id WHERE j.id = ? AND j.lease_token = ? AND n.is_read = 0 AND n.user_id = s.user_id', [(int) $item['id'], $lease]);
            if (!$row) continue;
            return ['id' => (int) $row['id'], 'lease' => $lease, 'attempts' => (int) $row['attempts'], 'notification_id' => (int) $row['notification_id'], 'subscription' => ['endpoint' => $row['endpoint'], 'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth_secret']]]];
        }
        return null;
    }
    public static function acknowledge(int $id, string $lease, string $result): void
    {
        if ($id <= 0 || !preg_match('/^[a-f0-9]{48}$/D', $lease) || !in_array($result, ['sent', 'gone', 'retry'], true)) throw new RuntimeException('پاسخ صف پوش نامعتبر است.');
        $row = Database::fetchOne('SELECT subscription_id, attempts FROM push_jobs WHERE id = ? AND lease_token = ?', [$id, $lease]);
        if (!$row) throw new RuntimeException('این درخواست منقضی شده است.');
        if ($result === 'gone') {
            Database::execute('DELETE FROM push_subscriptions WHERE id = ?', [(int) $row['subscription_id']]);
        } elseif ($result === 'sent') {
            Database::execute('DELETE FROM push_jobs WHERE id = ? AND lease_token = ?', [$id, $lease]);
        } else {
            $delay = min(3600, 30 * (2 ** min(7, max(0, (int) $row['attempts'] - 1))));
            Database::execute('UPDATE push_jobs SET lease_token = NULL, available_at = ? WHERE id = ? AND lease_token = ?', [date('Y-m-d H:i:s', time() + $delay), $id, $lease]);
        }
    }
}
