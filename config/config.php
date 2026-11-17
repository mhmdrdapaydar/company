<?php
declare(strict_types=1);

/**
 * تنظیمات اصلی پنل مدیریت شرکت و تسک‌ها (همکار پلاس)
 * -----------------------------------------------------
 * برای تنظیم پایگاه داده MySQL/MariaDB خود کافی است مقادیر DB_* زیر را ویرایش کنید
 * یا یک فایل .env / config.local.php بسازید.
 * سیستم به صورت کاملاً خودکار دیتابیس و تمام جداول را با کدگذاری فارسی utf8mb4_persian_ci می‌سازد.
 */

// بارگذاری فایل .env در صورت وجود
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $trimmed, 2);
        $k = trim($k);
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && getenv($k) === false) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

// بارگذاری تنظیمات محلی سفارشی در صورت وجود
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

if (!function_exists('app_env')) {
    function app_env(string $key, mixed $default = null): mixed
    {
        $val = $_ENV[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return $default;
        }
        return $val;
    }
}

// مسیرهای پایه پروژه
defined('BASE_PATH')        || define('BASE_PATH', dirname(__DIR__));
defined('STORAGE_PATH')     || define('STORAGE_PATH', BASE_PATH . '/storage');
defined('VOICES_PATH')      || define('VOICES_PATH', STORAGE_PATH . '/voices');
defined('ATTACHMENTS_PATH') || define('ATTACHMENTS_PATH', STORAGE_PATH . '/attachments');
defined('SQLITE_DB_PATH')   || define('SQLITE_DB_PATH', STORAGE_PATH . '/database/company_panel.sqlite');

// اطلاعات سامانه و منطقه زمانی ایران
defined('APP_NAME')         || define('APP_NAME', (string) app_env('APP_NAME', 'همکار پلاس'));
defined('APP_VERSION')      || define('APP_VERSION', '2.0.0');
defined('APP_TIMEZONE')     || define('APP_TIMEZONE', (string) app_env('APP_TIMEZONE', 'Asia/Tehran'));
defined('APP_DEBUG')        || define('APP_DEBUG', filter_var(app_env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));

/**
 * تنظیمات پایگاه داده SQL (پشتیبانی کامل از زبان فارسی)
 * DB_DRIVER:
 *   'auto'   -> ابتدا به MySQL/MariaDB متصل می‌شود و دیتابیس/جداول utf8mb4_persian_ci را می‌سازد؛
 *               در صورت عدم دسترسی به سرویس MySQL، خودکار روی SQLite (با UTF-8 کامل فارسی) بالا می‌آید.
 *   'mysql'  -> فقط MySQL / MariaDB (مناسب هاست لینوکس، cPanel، DirectAdmin، XAMPP، WAMP، Laragon)
 *   'sqlite' -> فقط SQLite (فایل SQL بدون نیاز به نصب سرویس جداگانه)
 */
defined('DB_DRIVER')    || define('DB_DRIVER', (string) app_env('DB_DRIVER', 'auto'));
defined('DB_HOST')      || define('DB_HOST', (string) app_env('DB_HOST', '127.0.0.1'));
defined('DB_PORT')      || define('DB_PORT', (int) app_env('DB_PORT', 3306));
defined('DB_NAME')      || define('DB_NAME', (string) app_env('DB_NAME', 'company_panel'));
defined('DB_USER')      || define('DB_USER', (string) app_env('DB_USER', 'root'));
defined('DB_PASS')      || define('DB_PASS', (string) app_env('DB_PASS', ''));
defined('DB_CHARSET')   || define('DB_CHARSET', 'utf8mb4');
defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_persian_ci');

// بارگذاری نمونه داده اولیه هنگام ایجاد اولین بار جداول
defined('DB_SEED_DEMO') || define('DB_SEED_DEMO', filter_var(app_env('DB_SEED_DEMO', 'true'), FILTER_VALIDATE_BOOLEAN));

/**
 * تنظیمات هوش مصنوعی رایگان برای پردازش صدا (ویس) و استخراج تسک
 * (از داخل بخش «تنظیمات سیستم» در پنل مدیریت نیز قابل تغییر است)
 *   'auto'         -> انتخاب هوشمند رایگان (Groq رایگان -> Gemini رایگان -> Pollinations رایگان بدون کلید -> موتور هوشمند فارسی داخلی)
 *   'groq'         -> نسخه رایگان Groq (Whisper Large v3 برای تبدیل صدا به متن فارسی + Llama 3.3 70B)
 *   'gemini'       -> نسخه رایگان Google Gemini Flash (پردازش مستقیم فایل صوتی فارسی + متن)
 *   'pollinations' -> سرویس رایگان Pollinations AI (کاملاً رایگان و بدون نیاز به کلید API)
 *   'local'        -> موتور پردازش زبان طبیعی فارسی داخلی (۱۰۰٪ رایگان، آنی، آفلاین و بدون کلید)
 */
defined('AI_PROVIDER')    || define('AI_PROVIDER', (string) app_env('AI_PROVIDER', 'auto'));
defined('GROQ_API_KEY')   || define('GROQ_API_KEY', (string) app_env('GROQ_API_KEY', ''));
defined('GEMINI_API_KEY') || define('GEMINI_API_KEY', (string) app_env('GEMINI_API_KEY', ''));

// محدودیت‌های امنیتی آپلود (بایت)
defined('MAX_VOICE_SIZE')      || define('MAX_VOICE_SIZE', 20 * 1024 * 1024);      // ۲۰ مگابایت
defined('MAX_ATTACHMENT_SIZE') || define('MAX_ATTACHMENT_SIZE', 25 * 1024 * 1024); // ۲۵ مگابایت
