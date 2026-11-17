<?php
declare(strict_types=1);

/**
 * راه‌انداز مرکزی سامانه: تنظیم UTF-8 فارسی، ساعت رسمی ایران، نشست امن و آماده‌سازی خودکار پایگاه داده SQL
 */

require_once dirname(__DIR__) . '/config/config.php';

// تنظیم کامل کدگذاری فارسی UTF-8
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
    mb_regex_encoding('UTF-8');
}

// تنظیم منطقه زمانی رسمی ایران
date_default_timezone_set(APP_TIMEZONE);

// مدیریت نمایش خطاها
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

// راه‌اندازی امن سشن (نشست کاربری)
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['HTTP_FORWARDED'] ?? '')));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || str_contains($forwardedProto, 'https')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https';

    // اگر مسیر پیش‌فرض سشن قابل نوشتن نبود، پوشه داخلی امن را استفاده کن
    $defaultSavePath = session_save_path();
    if ($defaultSavePath === '' || !@is_writable($defaultSavePath)) {
        $localSessionDir = STORAGE_PATH . '/sessions';
        if (!is_dir($localSessionDir)) {
            @mkdir($localSessionDir, 0700, true);
        }
        if (is_dir($localSessionDir) && is_writable($localSessionDir)) {
            session_save_path($localSessionDir);
        }
    }

    session_name('HAMKAR_SESSION');
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax',
    ]);
    session_start();
}

// بارگذاری کلاس‌ها و توابع اصلی سامانه
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Jalali.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/PersianVoiceParser.php';
require_once __DIR__ . '/AiService.php';

// اتصال به پایگاه داده SQL و ایجاد خودکار تمام جداول فارسی در صورت عدم وجود
try {
    Database::pdo();
    Schema::ensureInitialized();
} catch (Throwable $dbException) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $safeMsg = e($dbException->getMessage());
    echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خطا در اتصال به پایگاه داده</title>
    <style>
        body { font-family: Tahoma, Vazirmatn, sans-serif; background: #f8fafc; color: #0f172a; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; max-width: 560px; width: 100%; padding: 28px; box-shadow: 0 10px 30px rgba(15,23,42,.06); line-height: 1.9; }
        h1 { margin: 0 0 12px; font-size: 20px; color: #be123c; }
        code { display: block; background: #fff1f2; color: #9f1239; padding: 12px; border-radius: 10px; font-size: 13px; direction: ltr; text-align: left; margin: 12px 0; word-break: break-all; }
        .hint { font-size: 14px; color: #475569; background: #f1f5f9; padding: 12px 16px; border-radius: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>خطا در اتصال به پایگاه داده SQL</h1>
        <p>سامانه نتوانست به پایگاه داده متصل شود. لطفاً مشخصات اتصال را در فایل <strong>config/config.php</strong> یا فایل <strong>.env</strong> بررسی نمایید:</p>
        <code>{$safeMsg}</code>
        <div class="hint">پس از تنظیم صحیح نام کاربری و رمز عبور MySQL، صفحه را مجدداً بارگذاری کنید تا دیتابیس و تمامی جداول فارسی به صورت خودکار ایجاد شوند.</div>
    </div>
</body>
</html>
HTML;
    exit;
}
