<?php
declare(strict_types=1);

/** FCM delivery and device receipt are deliberately separate states. */
final class AndroidPush
{
    public static function enabled(): bool
    {
        return filter_var(app_env('ANDROID_PUSH_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)
            && strlen((string) app_env('ANDROID_PUSH_WORKER_TOKEN', '')) >= 32;
    }
    public static function verifyWorker(string $token): bool
    {
        $expected = (string) app_env('ANDROID_PUSH_WORKER_TOKEN', '');
        return self::enabled() && hash_equals($expected, $token);
    }
    public static function register(int $tokenId, int $userId, string $fcm): void
    {
        if (!self::enabled()) throw new RuntimeException('پوش اندروید در سرور فعال نیست.', 503);
        if (strlen($fcm) < 16 || strlen($fcm) > 4096 || !preg_match('/^[A-Za-z0-9_:\-.]+$/D', $fcm)) throw new RuntimeException('توکن دستگاه نامعتبر است.', 400);
        $hash = hash('sha256', $fcm); $now = time();
        Database::transaction(function () use ($tokenId, $userId, $fcm, $hash, $now): void {
            $current = Database::fetchOne('SELECT id FROM android_push_devices WHERE token_id = ? AND user_id = ?', [$tokenId, $userId]);
            $other = Database::fetchOne('SELECT id FROM android_push_devices WHERE fcm_hash = ?', [$hash]);
            if ($other && (!$current || (int) $other['id'] !== (int) $current['id'])) Database::execute('DELETE FROM android_push_devices WHERE id = ?', [(int) $other['id']]);
            if ($current) {
                Database::execute('UPDATE android_push_devices SET fcm_hash = ?, fcm_token = ?, updated_at = ? WHERE id = ?', [$hash, $fcm, $now, (int) $current['id']]);
            } else {
                if ((int) Database::fetchValue('SELECT COUNT(*) FROM android_push_devices WHERE user_id = ?', [$userId], 0) >= 10) throw new RuntimeException('حداکثر تعداد دستگاه‌های پوش ثبت شده است.', 400);
                Database::insert('INSERT INTO android_push_devices (token_id, user_id, fcm_hash, fcm_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$tokenId, $userId, $hash, $fcm, $now, $now]);
            }
        });
    }
    public static function unregister(int $tokenId, int $userId): void
    {
        Database::transaction(function () use ($tokenId, $userId): void {
            Database::execute("UPDATE android_push_deliveries SET state='cancelled', lease_token=NULL WHERE state='queued' AND device_id IN (SELECT id FROM android_push_devices WHERE token_id=? AND user_id=?)", [$tokenId, $userId]);
            Database::execute('DELETE FROM android_push_devices WHERE token_id = ? AND user_id = ?', [$tokenId, $userId]);
        });
    }
    public static function enqueue(int $notificationId, int $userId): void
    {
        if (!self::enabled()) return;
        $devices = Database::fetchAll('SELECT d.id, t.last_used_at, t.password_fingerprint, u.password_hash, u.is_active FROM android_push_devices d JOIN mobile_tokens t ON t.id=d.token_id JOIN users u ON u.id=d.user_id WHERE d.user_id = ?', [$userId]);
        $now = time();
        foreach ($devices as $device) {
            if (!(int) $device['is_active'] || (int) $device['last_used_at'] < $now - MobileAuth::IDLE_SECONDS || !hash_equals((string) $device['password_fingerprint'], hash('sha256', (string) $device['password_hash']))) continue;
            Database::insert('INSERT INTO android_push_deliveries (notification_id, device_id, state, attempts, available_at, created_at) VALUES (?, ?, ?, 0, ?, ?)', [$notificationId, (int) $device['id'], 'queued', $now, $now]);
        }
    }
    public static function claim(): ?array
    {
        $now = time();
        // Expire pending work after 7 days and old receipt metadata after 90 days.
        Database::execute("DELETE FROM android_push_deliveries WHERE (state = 'queued' AND created_at < ?) OR created_at < ?", [$now - 7 * 86400, $now - 90 * 86400]);
        $rows = Database::fetchAll("SELECT id FROM android_push_deliveries WHERE state = 'queued' AND device_id IS NOT NULL AND available_at <= ? ORDER BY id ASC LIMIT 20", [$now]);
        foreach ($rows as $item) {
            $lease = bin2hex(random_bytes(24));
            if (!Database::execute("UPDATE android_push_deliveries SET lease_token=?, attempts=attempts+1, available_at=? WHERE id=? AND state='queued' AND available_at <= ?", [$lease, $now + 120, (int) $item['id'], $now])) continue;
            $r = Database::fetchOne('SELECT j.id,j.notification_id,j.attempts,d.fcm_token,d.fcm_hash,t.last_used_at,t.password_fingerprint,u.password_hash,u.is_active,n.is_read,n.user_id,d.user_id AS device_user FROM android_push_deliveries j JOIN android_push_devices d ON d.id=j.device_id JOIN mobile_tokens t ON t.id=d.token_id JOIN users u ON u.id=d.user_id JOIN notifications n ON n.id=j.notification_id WHERE j.id=? AND j.lease_token=?', [(int) $item['id'], $lease]);
            if (!$r) continue;
            if ((int) $r['is_read'] || (int) $r['user_id'] !== (int) $r['device_user'] || !(int) $r['is_active'] || (int) $r['last_used_at'] < $now - MobileAuth::IDLE_SECONDS || !hash_equals((string) $r['password_fingerprint'], hash('sha256', (string) $r['password_hash']))) {
                Database::execute("UPDATE android_push_deliveries SET state='cancelled', lease_token=NULL WHERE id=? AND lease_token=?", [(int) $item['id'], $lease]);
                continue;
            }
            return ['id'=>(int) $r['id'], 'notification_id'=>(int) $r['notification_id'], 'lease'=>$lease, 'attempts'=>(int) $r['attempts'], 'fcm_token'=>$r['fcm_token'], 'fcm_hash'=>$r['fcm_hash']];
        }
        return null;
    }
    public static function workerAck(int $id, string $lease, string $fcmHash, string $result): void
    {
        if ($id <= 0 || !preg_match('/^[a-f0-9]{48}$/D', $lease) || !preg_match('/^[a-f0-9]{64}$/D', $fcmHash) || !in_array($result, ['sent', 'gone', 'retry'], true)) throw new RuntimeException('پاسخ صف نامعتبر است.', 400);
        $r = Database::fetchOne('SELECT j.device_id,j.attempts,j.received_at,j.state,d.fcm_hash FROM android_push_deliveries j LEFT JOIN android_push_devices d ON d.id=j.device_id WHERE j.id=? AND j.lease_token=?', [$id, $lease]);
        if (!$r) throw new RuntimeException('درخواست صف منقضی شده است.', 409);
        // Receipt can race with FCM response. Never overwrite a device confirmation.
        if ($r['received_at'] !== null) { Database::execute('UPDATE android_push_deliveries SET lease_token=NULL WHERE id=? AND lease_token=?', [$id, $lease]); return; }
        if (!$r['device_id']) { Database::execute("UPDATE android_push_deliveries SET state='cancelled',lease_token=NULL WHERE id=? AND lease_token=?", [$id, $lease]); return; }
        if (!hash_equals((string) $r['fcm_hash'], $fcmHash)) $result = 'retry';
        if ($result === 'gone') {
            $updated = Database::execute("UPDATE android_push_deliveries SET state='gone',lease_token=NULL WHERE id=? AND lease_token=? AND received_at IS NULL", [$id, $lease]);
            if ($updated) Database::execute('DELETE FROM android_push_devices WHERE id=? AND fcm_hash=?', [(int) $r['device_id'], $fcmHash]);
        } elseif ($result === 'sent') {
            Database::execute("UPDATE android_push_deliveries SET state='sent',sent_at=?,lease_token=NULL WHERE id=? AND lease_token=? AND received_at IS NULL", [time(), $id, $lease]);
        } else {
            $delay = min(3600, 30 * (2 ** min(7, max(0, (int) $r['attempts'] - 1))));
            Database::execute("UPDATE android_push_deliveries SET lease_token=NULL,available_at=? WHERE id=? AND lease_token=? AND received_at IS NULL", [time() + $delay, $id, $lease]);
        }
    }
    public static function receipt(int $tokenId, int $userId, int $notificationId): bool
    {
        if ($notificationId <= 0) throw new RuntimeException('شناسه اعلان نامعتبر است.', 400);
        $r = Database::fetchOne('SELECT j.id,j.received_at FROM android_push_deliveries j JOIN android_push_devices d ON d.id=j.device_id JOIN notifications n ON n.id=j.notification_id WHERE d.token_id=? AND d.user_id=? AND n.user_id=? AND n.id=?', [$tokenId, $userId, $userId, $notificationId]);
        if (!$r) throw new RuntimeException('اعلان برای این دستگاه یافت نشد.', 404);
        $changed = Database::execute("UPDATE android_push_deliveries SET received_at=?,state='received' WHERE id=? AND received_at IS NULL", [time(), (int) $r['id']]);
        return $changed === 0;
    }
    public static function status(int $userId, int $notificationId, bool $admin): array
    {
        $n = Database::fetchOne('SELECT id,actor_id,user_id FROM notifications WHERE id=?', [$notificationId]);
        if (!$n || (!$admin && (int) ($n['actor_id'] ?? 0) !== $userId && (int) $n['user_id'] !== $userId)) throw new RuntimeException('اعلان یافت نشد.', 404);
        $rows = Database::fetchAll("SELECT CASE WHEN state='queued' AND device_id IS NULL THEN 'cancelled' ELSE state END AS state,COUNT(*) AS c FROM android_push_deliveries WHERE notification_id=? GROUP BY CASE WHEN state='queued' AND device_id IS NULL THEN 'cancelled' ELSE state END", [$notificationId]);
        $counts = ['queued'=>0, 'sent'=>0, 'received'=>0, 'gone'=>0, 'cancelled'=>0];
        foreach ($rows as $row) if (isset($counts[$row['state']])) $counts[$row['state']] += (int) $row['c'];
        return $counts;
    }
}
