<?php
declare(strict_types=1);

/**
 * مدیریت احراز هویت، نشست‌ها و کنترل سطح دسترسی نقش‌ها (مدیر شرکت، سرپرست واحد، کارمند)
 */
final class Auth
{
    private static ?array $currentUser = null;
    private static bool $loaded = false;
    private static ?int $mobileUserId = null;

    public static function useMobileUser(int $userId): void
    {
        self::$mobileUserId = $userId; self::$loaded = false; self::$currentUser = null;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$currentUser;
        }
        self::$loaded = true;

        $uid = self::$mobileUserId ?? (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return null;
        }

        $user = Database::fetchOne(
            'SELECT u.*, d.name AS department_name, d.color AS department_color
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.id = ? AND u.is_active = 1',
            [$uid]
        );

        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }

        $user['id']            = (int) $user['id'];
        $user['department_id'] = $user['department_id'] !== null ? (int) $user['department_id'] : null;
        $user['is_active']     = (int) $user['is_active'];
        $user['role_label']    = user_role_label((string) $user['role']);
        $user['initials']      = persian_initials((string) $user['full_name']);
        // 🔒 هرگز هش رمز عبور را در آبجکت کاربر نگه نداریم:
        // این آبجکت در پاسخ‌های bootstrap / me / profile_update به مرورگر می‌رود.
        unset($user['password_hash'], $user['remember_token'], $user['reset_token']);
        self::$currentUser     = $user;

        return self::$currentUser;
    }

    public static function refresh(): ?array
    {
        self::$loaded = false;
        self::$currentUser = null;
        return self::user();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function isManager(): bool
    {
        return in_array(self::user()['role'] ?? '', ['admin', 'manager'], true);
    }

    /**
     * ورود کاربر با نام کاربری یا ایمیل یا کد پرسنلی
     */
    /** حداکثر تلاش ناموفق پیش از قفل موقت */
    private const MAX_LOGIN_ATTEMPTS = 5;
    /** مدت قفل (ثانیه) */
    private const LOGIN_LOCK_SECONDS = 300;

    /**
     * 🔒 محدودیت تلاش ورود (ضد حملهٔ حدس رمز)
     * کلید بر پایهٔ نام کاربری + آی‌پی است تا مهاجم نتواند با پاک‌کردن کوکی
     * محدودیت را دور بزند، ولی قفل یک کاربر، ورود بقیه را نبندد.
     * @return int ثانیهٔ باقی‌ماندهٔ قفل (۰ = آزاد)
     */
    private static function loginLockRemaining(string $identifier): int
    {
        $data = $_SESSION['_login_throttle'] ?? ['fails' => 0, 'until' => 0];
        if (!is_array($data) || (int) ($data['until'] ?? 0) <= time()) {
            return 0;
        }
        return (int) $data['until'] - time();
    }

    private static function registerFailedLogin(): void
    {
        $data = $_SESSION['_login_throttle'] ?? ['fails' => 0, 'until' => 0];
        if (!is_array($data)) {
            $data = ['fails' => 0, 'until' => 0];
        }
        $data['fails'] = (int) ($data['fails'] ?? 0) + 1;
        if ($data['fails'] >= self::MAX_LOGIN_ATTEMPTS) {
            $data['until'] = time() + self::LOGIN_LOCK_SECONDS;
            $data['fails'] = 0;
        }
        $_SESSION['_login_throttle'] = $data;
    }

    private static function clearLoginThrottle(): void
    {
        unset($_SESSION['_login_throttle']);
    }

    public static function attempt(string $identifier, string $password): array
    {
        $idClean = normalize_persian($identifier, true);
        if ($idClean === '' || $password === '') {
            return ['ok' => false, 'message' => 'لطفاً نام کاربری و رمز عبور را وارد کنید.'];
        }

        $remaining = self::loginLockRemaining($idClean);
        if ($remaining > 0) {
            $mins = (int) ceil($remaining / 60);
            return ['ok' => false, 'message' => 'به دلیل تلاش‌های ناموفق متعدد، ورود موقتاً قفل شده است. لطفاً ' . fa_digits($mins) . ' دقیقهٔ دیگر تلاش کنید.'];
        }

        $user = Database::fetchOne(
            'SELECT * FROM users WHERE username = ? OR email = ? OR employee_code = ? LIMIT 1',
            [$idClean, $idClean, strtoupper($idClean)]
        );

        // شماره تلفن فقط در صورت یکتا بودن قابل استفاده برای ورود است.
        if (!$user) {
            $phone = preg_replace('/[^0-9]/', '', $idClean);
            if ($phone !== '' && strlen($phone) >= 10 && strlen($phone) <= 15) {
                $matches = [];
                foreach (Database::fetchAll('SELECT * FROM users WHERE is_active = 1 AND phone IS NOT NULL AND phone <> ?', ['']) as $candidate) {
                    $normalized = preg_replace('/[^0-9]/', '', normalize_persian((string) $candidate['phone'], true));
                    if ($normalized === $phone) $matches[] = $candidate;
                }
                if (count($matches) === 1) $user = $matches[0];
            }
        }
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            self::registerFailedLogin();
            $left = self::MAX_LOGIN_ATTEMPTS - (int) ($_SESSION['_login_throttle']['fails'] ?? 0);
            $hint = ($left > 0 && $left <= 2)
                ? ' (' . fa_digits($left) . ' تلاش دیگر باقی مانده است.)'
                : '';
            return ['ok' => false, 'message' => 'نام کاربری یا رمز عبور نادرست است.' . $hint];
        }

        if ((int) $user['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'حساب کاربری شما غیرفعال شده است. با مدیر شرکت تماس بگیرید.'];
        }

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        self::clearLoginThrottle();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $now = now_datetime();
        Database::execute('UPDATE users SET last_login_at = ? WHERE id = ?', [$now, (int) $user['id']]);
        log_activity((int) $user['id'], 'login', 'user', (int) $user['id'], $user['full_name'] . ' وارد سامانه شد.');

        self::$loaded = false;
        return ['ok' => true, 'user' => self::user()];
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) {
            log_activity($u['id'], 'logout', 'user', $u['id'], $u['full_name'] . ' از سامانه خارج شد.');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$currentUser = null;
        self::$loaded = true;
    }

    /**
     * بررسی دسترسی مشاهده یک تسک
     */
    public static function canViewTask(array $task): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role'] === 'admin') {
            return true;
        }
        if ((int) $task['assigned_to'] === $u['id'] || (int) $task['created_by'] === $u['id']) {
            return true;
        }
        if ($u['role'] === 'manager' && $u['department_id'] !== null && (int) ($task['department_id'] ?? 0) === $u['department_id']) {
            return true;
        }
        return false;
    }

    /**
     * بررسی مجوز کارمند/مدیر برای واگذاری تسک به کاربر هدف
     */
    public static function canAssignTo(int $targetUserId): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if (in_array($u['role'], ['admin', 'manager'], true)) {
            return true;
        }
        if ($targetUserId === $u['id']) {
            return get_setting('allow_employee_self_task', '1') === '1' || $u['role'] === 'manager';
        }
        if ($u['role'] === 'manager') {
            $target = Database::fetchOne('SELECT department_id, role FROM users WHERE id = ? AND is_active = 1', [$targetUserId]);
            if (!$target) {
                return false;
            }
            return $u['department_id'] !== null && (int) ($target['department_id'] ?? 0) === $u['department_id'];
        }
        // کارمند عادی در صورت فعال بودن گزینه واگذاری همکار در تنظیمات
        return get_setting('allow_employee_peer_task', '0') === '1';
    }

    /**
     * فهرست کارمندانی که کاربر جاری مجاز به تعریف تسک برای آن‌هاست
     */
    public static function assignableUsers(): array
    {
        $u = self::user();
        if (!$u) {
            return [];
        }
        if (in_array($u['role'], ['admin', 'manager'], true) || get_setting('allow_employee_peer_task', '0') === '1') {
            return Database::fetchAll(
                'SELECT u.id, u.full_name, u.employee_code, u.role, u.position_title, u.department_id, u.avatar_color, d.name AS department_name
                 FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.is_active = 1
                 ORDER BY u.id ASC'
            );
        }
        if ($u['role'] === 'manager' && $u['department_id'] !== null) {
            return Database::fetchAll(
                'SELECT u.id, u.full_name, u.employee_code, u.role, u.position_title, u.department_id, u.avatar_color, d.name AS department_name
                 FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.is_active = 1 AND (u.department_id = ? OR u.id = ?)
                 ORDER BY u.id ASC',
                [$u['department_id'], $u['id']]
            );
        }
        return Database::fetchAll(
            'SELECT u.id, u.full_name, u.employee_code, u.role, u.position_title, u.department_id, u.avatar_color, d.name AS department_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.id = ?',
            [$u['id']]
        );
    }
}
