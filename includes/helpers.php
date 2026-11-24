<?php
declare(strict_types=1);

/**
 * توابع کمکی عمومی، فارسی‌سازی، امنیت و مدیریت فایل‌ها
 */

/**
 * فرار امن HTML با پشتیبانی کامل از UTF-8 فارسی
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * تبدیل ارقام انگلیسی و عربی به فارسی
 */
function fa_digits(string|int|float|null $input): string
{
    if ($input === null) {
        return '';
    }
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str_replace($en, $fa, str_replace($ar, $fa, (string) $input));
}

/**
 * تبدیل ارقام فارسی و عربی به انگلیسی (برای ذخیره و محاسبه)
 */
function en_digits(string|int|float|null $input): string
{
    if ($input === null) {
        return '';
    }
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($ar, $en, str_replace($fa, $en, (string) $input));
}

/**
 * حذف کاراکترهای اضافی از ابتدا و انتهای رشته با پشتیبانی کامل از UTF-8
 * (برخلاف trim() که charlist را بایت‌به‌بایت حذف می‌کند و حروف فارسی چندبایتی را خراب می‌کند)
 */
function trim_fa(string $value, string $extra = ''): string
{
    $chars = " \t\n\r\0\x0B" . $extra;
    $pattern = '/^[' . preg_quote($chars, '/') . ']+|[' . preg_quote($chars, '/') . ']+$/u';
    $result = preg_replace($pattern, '', $value);
    return $result === null ? trim($value) : $result;
}

/**
 * یکسان‌سازی حروف فارسی (تبدیل ي/ى/ئ عربی به ی فارسی و ك به ک، حذف کشیده و کاراکترهای اضافی)
 */
function normalize_persian(?string $text, bool $convertDigitsToEn = false): string
{
    if ($text === null || $text === '') {
        return '';
    }
    $search  = ['ي', 'ى', 'ك', 'ة', 'ؤ', 'أ', 'إ', 'ـ', "\r\n", "\r"];
    $replace = ['ی', 'ی', 'ک', 'ه', 'و', 'ا', 'ا', '',  "\n",   "\n"];
    $result  = str_replace($search, $replace, $text);
    // تبدیل فاصله‌های غیرعادی به فاصله استاندارد با حفظ نیم‌فاصله (\u200C)
    $result  = preg_replace('/[ \t\x{00A0}]+/u', ' ', $result) ?? $result;
    // حذف کاراکترهای نامرئی کنترل جهت متن (LRM/RLM/ALM) که از کیبورد یا گفتار می‌رسند
    $result  = preg_replace('/[\x{200E}\x{200F}\x{061C}]/u', '', $result) ?? $result;
    if ($convertDigitsToEn) {
        $result = en_digits($result);
    }
    return trim_fa($result);
}

/**
 * تمیزسازی ورودی متنی تک‌خطی
 */
function clean_line(mixed $value, int $maxLength = 255): string
{
    // ⚠️ آرایه/آبجکت نباید بی‌صدا به رشته «Array» تبدیل شود
    // (پیش‌تر title=['a'] یک تسک با عنوان «Array» می‌ساخت و PHP Warning می‌داد)
    if ($value !== null && !is_scalar($value)) {
        return '';
    }
    $str = normalize_persian((string) ($value ?? ''));
    $str = str_replace("\n", ' ', $str);
    $str = preg_replace('/\s+/u', ' ', $str) ?? $str;
    return mb_substr(trim($str), 0, $maxLength, 'UTF-8');
}

/**
 * تمیزسازی ورودی متنی چندخطی
 */
function clean_text(mixed $value, int $maxLength = 10000): string
{
    if ($value !== null && !is_scalar($value)) {
        return '';
    }
    $str = normalize_persian((string) ($value ?? ''));
    return mb_substr(trim($str), 0, $maxLength, 'UTF-8');
}

/**
 * تولید و بررسی توکن امنیتی CSRF
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * ارسال پاسخ استاندارد JSON فارسی
 */
function json_response(array $payload, int $statusCode = 200): never
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $statusCode = 400, array $extra = []): never
{
    json_response(array_merge(['ok' => false, 'message' => $message], $extra), $statusCode);
}

function json_success(array $data = [], string $message = ''): never
{
    $payload = ['ok' => true];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    json_response(array_merge($payload, $data), 200);
}

/**
 * خواندن ورودی درخواست (فرم معمولی یا بدنه JSON)
 */
function request_data(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cached = array_merge($_GET, $decoded);
                return $cached;
            }
        }
    }
    $cached = array_merge($_GET, $_POST);
    return $cached;
}

/**
 * زمان فعلی سیستم به وقت تهران
 */
function now_datetime(): string
{
    return date('Y-m-d H:i:s');
}

function today_date(): string
{
    return date('Y-m-d');
}

/**
 * استخراج حروف اول نام فارسی برای آواتار
 */
function persian_initials(string $fullName): string
{
    $name = trim(normalize_persian($fullName));
    if ($name === '') {
        return 'ک';
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
    if (count($parts) > 1) {
        $last = mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8');
        return $first . '‌' . $last;
    }
    return $first;
}

/**
 * فرمت نمایش حجم فایل به فارسی
 */
function format_bytes_fa(int $bytes): string
{
    if ($bytes < 1024) {
        return fa_digits($bytes) . ' بایت';
    }
    if ($bytes < 1048576) {
        return fa_digits(round($bytes / 1024, 1)) . ' کیلوبایت';
    }
    return fa_digits(round($bytes / 1048576, 1)) . ' مگابایت';
}

/**
 * فرمت مدت زمان (دقیقه) به فارسی خوانا
 */
function format_minutes_fa(int $minutes): string
{
    if ($minutes <= 0) {
        return '۰ دقیقه';
    }
    $hours = intdiv($minutes, 60);
    $mins  = $minutes % 60;
    if ($hours > 0 && $mins > 0) {
        return fa_digits($hours) . ' ساعت و ' . fa_digits($mins) . ' دقیقه';
    }
    if ($hours > 0) {
        return fa_digits($hours) . ' ساعت';
    }
    return fa_digits($mins) . ' دقیقه';
}

/**
 * برچسب‌های فارسی وضعیت تسک، اولویت و نقش
 */
function task_status_label(string $status): string
{
    return match ($status) {
        'todo'        => 'در انتظار انجام',
        'in_progress' => 'در حال انجام',
        'review'      => 'در حال انجام',
        'done'        => 'انجام‌شده',
        default       => $status,
    };
}

function task_priority_label(string $priority): string
{
    return match ($priority) {
        'urgent' => 'فوری',
        'high'   => 'بالا',
        'medium' => 'متوسط',
        'low'    => 'پایین',
        default  => $priority,
    };
}

/**
 * ماسک‌کردن کلید API برای نمایش امن در رابط کاربری
 * (هرگز کلید کامل به مرورگر فرستاده نمی‌شود)
 */
function mask_api_key(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }
    $len = strlen($key);
    if ($len <= 8) {
        return str_repeat('•', $len);
    }
    return substr($key, 0, 4) . str_repeat('•', 12) . substr($key, -4);
}


function user_role_label(string $role): string
{
    return match ($role) {
        'admin'    => 'مدیر شرکت',
        'manager'  => 'مدیر',
        'employee' => 'کارمند',
        default    => $role,
    };
}

/**
 * برچسب فارسی برای اکشن‌های گزارش فعالیت
 * (به‌جای نمایش کد خام انگلیسی مثل clock_out)
 */
function activity_action_label(string $action): string
{
    static $map = [
        'create_task'       => 'ایجاد تسک',
        'update_task'       => 'ویرایش تسک',
        'delete_task'       => 'حذف تسک',
        'comment'           => 'ثبت نظر',
        'attach'            => 'افزودن پیوست',
        'clock_in'          => 'ثبت ورود',
        'clock_out'         => 'ثبت خروج',
        'attendance_manual' => 'ثبت دستی حضور',
        'attendance_delete' => 'حذف رکورد حضور',
        'leave_request'     => 'درخواست مرخصی',
        'leave_review'      => 'بررسی مرخصی',
        'create_user'       => 'ایجاد کارمند',
        'update_user'       => 'ویرایش کارمند',
        'delete_user'       => 'حذف کارمند',
        'create_project'    => 'ایجاد پروژه',
        'update_project'    => 'ویرایش پروژه',
        'delete_project'    => 'حذف پروژه',
        'create_department' => 'ایجاد واحد',
        'update_department' => 'ویرایش واحد',
        'delete_department' => 'حذف واحد',
        'announcement'      => 'اطلاعیه',
        'voice_preview'     => 'پردازش صوتی',
        'voice_create'      => 'ایجاد تسک صوتی',
        'settings'          => 'تغییر تنظیمات',
        'password_change'   => 'تغییر رمز عبور',
        'profile_update'    => 'ویرایش پروفایل',
        'subtask_toggle'    => 'تغییر وضعیت زیرتسک',
        'subtask_add'       => 'افزودن زیرتسک',
        'subtask_delete'    => 'حذف زیرتسک',
        'timer_start'       => 'شروع زمان‌سنج',
        'timer_stop'        => 'پایان زمان‌سنج',
        'login'             => 'ورود به سامانه',
        'logout'            => 'خروج از سامانه',
    ];
    return $map[$action] ?? $action;
}

function leave_type_label(string $type): string
{
    return match ($type) {
        'daily'   => 'مرخصی روزانه',
        'hourly'  => 'مرخصی ساعتی',
        'sick'    => 'مرخصی استعلاجی',
        'mission' => 'مأموریت کاری',
        default   => $type,
    };
}

function leave_status_label(string $status): string
{
    return match ($status) {
        'pending'  => 'در انتظار تأیید',
        'approved' => 'تأیید شده',
        'rejected' => 'رد شده',
        default    => $status,
    };
}

function work_mode_label(string $mode): string
{
    return match ($mode) {
        'office'  => 'حضوری در شرکت',
        'remote'  => 'دورکاری',
        'mission' => 'مأموریت خارج از شرکت',
        default   => $mode,
    };
}

/**
 * خواندن و ذخیره تنظیمات سیستم در جدول settings
 */
function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = Database::fetchAll('SELECT setting_key, setting_value FROM settings');
            foreach ($rows as $r) {
                $cache[(string) $r['setting_key']] = (string) ($r['setting_value'] ?? '');
            }
        } catch (Throwable) {
            return $default;
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $now = now_datetime();
    $existing = Database::fetchOne('SELECT id FROM settings WHERE setting_key = ?', [$key]);
    if ($existing) {
        Database::execute(
            'UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?',
            [$value, $now, $key]
        );
    } else {
        Database::execute(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
            [$key, $value, $now]
        );
    }
}

/**
 * ثبت رویداد در جدول گزارش فعالیت‌ها
 */
function log_activity(?int $userId, string $action, string $entityType, ?int $entityId, string $description): void
{
    try {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        Database::execute(
            'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $action, $entityType, $entityId, mb_substr($description, 0, 500, 'UTF-8'), $ip, now_datetime()]
        );
    } catch (Throwable) {
        // خطای لاگ نباید عملیات اصلی کاربر را متوقف کند
    }
}

/**
 * ایجاد اعلان جدید برای یک کاربر
 */
function create_notification(
    int $userId,
    ?int $actorId,
    string $type,
    string $title,
    string $message,
    string $entityType = 'task',
    ?int $entityId = null,
    bool $required = false
): void {
    if ($actorId !== null && $userId === $actorId) {
        return;
    }
    try {
        $notificationId = Database::insert(
            'INSERT INTO notifications (user_id, actor_id, type, title, message, entity_type, entity_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
            [
                $userId,
                $actorId,
                $type,
                mb_substr($title, 0, 190, 'UTF-8'),
                mb_substr($message, 0, 500, 'UTF-8'),
                $entityType,
                $entityId,
                now_datetime(),
            ]
        );
        try { PushService::enqueue($notificationId, $userId); } catch (Throwable $pushError) { error_log('Push queue error: ' . $pushError->getMessage()); }
    } catch (Throwable $e) {
        error_log('Notification insert failed: ' . $e->getMessage());
        if ($required) throw $e;
    }
}

/**
 * ذخیره امن فایل صوتی ویس (آپلود مستقیم یا Base64)
 * خروجی: نام فایل ذخیره‌شده یا null
 */
function save_uploaded_voice(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('خطا در دریافت فایل صوتی.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_VOICE_SIZE) {
        throw new RuntimeException('حجم فایل صوتی نامعتبر است (حداکثر ۲۰ مگابایت).');
    }

    $origName = strtolower((string) ($file['name'] ?? 'voice.webm'));
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $allowedExt = ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4', 'aac', 'opus', 'flac'];

    $mime = '';
    if (function_exists('finfo_open') && !empty($file['tmp_name']) && is_file($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = strtolower((string) finfo_file($finfo, $file['tmp_name']));
            finfo_close($finfo);
        }
    }
    if ($mime === '') {
        $mime = strtolower((string) ($file['type'] ?? 'audio/webm'));
    }

    // برخی مرورگرها ویس WebM را با MIME video/webm یا application/octet-stream ارسال می‌کنند
    $mimeAllowed = str_starts_with($mime, 'audio/')
        || in_array($mime, ['video/webm', 'video/mp4', 'video/ogg', 'application/ogg', 'application/octet-stream'], true);

    if (!$mimeAllowed) {
        throw new RuntimeException('فرمت فایل صوتی پشتیبانی نمی‌شود.');
    }

    if (!in_array($ext, $allowedExt, true)) {
        $ext = match (true) {
            str_contains($mime, 'ogg')  => 'ogg',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a') => 'm4a',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav')  => 'wav',
            default                     => 'webm',
        };
    }

    if (!is_dir(VOICES_PATH)) {
        mkdir(VOICES_PATH, 0755, true);
    }

    $storedName = 'voice_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = VOICES_PATH . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        if (!@rename($file['tmp_name'], $targetPath) && !@copy($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('ذخیره فایل صوتی در سرور با خطا مواجه شد.');
        }
    }

    $normalizedMime = match ($ext) {
        'webm' => 'audio/webm',
        'ogg', 'opus' => 'audio/ogg',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'm4a', 'mp4' => 'audio/mp4',
        'aac'  => 'audio/aac',
        'flac' => 'audio/flac',
        default => 'audio/webm',
    };

    return [
        'stored_name' => $storedName,
        'full_path'   => $targetPath,
        'mime_type'   => $normalizedMime,
        'size'        => $size,
        'ext'         => $ext,
    ];
}

/**
 * ذخیره امن فایل پیوست تسک
 */
function save_uploaded_attachment(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('خطا در آپلود فایل پیوست.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_ATTACHMENT_SIZE) {
        throw new RuntimeException('حجم فایل پیوست بیش از حد مجاز است (حداکثر ۲۵ مگابایت).');
    }

    $origName = clean_line(basename((string) ($file['name'] ?? 'file')), 180);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    $allowed = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'rtf',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'zip', 'rar', '7z',
        'mp3', 'wav', 'ogg', 'webm', 'm4a', 'mp4',
        'json', 'xml',
    ];
    // جلوگیری کامل از آپلود اسکریپت‌های اجرایی
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        throw new RuntimeException('این پسوند فایل به دلایل امنیتی مجاز نیست.');
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open') && !empty($file['tmp_name']) && is_file($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $file['tmp_name']);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
            finfo_close($finfo);
        }
    }

    if (!is_dir(ATTACHMENTS_PATH)) {
        mkdir(ATTACHMENTS_PATH, 0755, true);
    }

    $storedName = 'att_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = ATTACHMENTS_PATH . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        if (!@rename($file['tmp_name'], $targetPath) && !@copy($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('ذخیره فایل پیوست در سرور ناموفق بود.');
        }
    }

    return [
        'original_name' => $origName,
        'stored_name'   => $storedName,
        'mime_type'     => $mime,
        'file_size'     => $size,
    ];
}
