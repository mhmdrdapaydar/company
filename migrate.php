<?php
declare(strict_types=1);
/**
 * ابزار انتقال داده از SQLite به MySQL/MariaDB
 * ------------------------------------------------------------------
 * کاربرد: وقتی سایت را روی هاست با دیتابیس MySQL بالا آورده‌اید و می‌خواهید
 * داده‌های قبلی (که در فایل SQLite ذخیره شده بودند) به آن منتقل شوند.
 *
 * دو حالت:
 *  ۱) از خط فرمان:  php migrate.php
 *  ۲) از مرورگر:   migrate.php  (فقط برای مدیر شرکت و با تأیید CSRF)
 *
 * این ابزار فقط به دیتابیس مقصد (DB_NAME در فایل .env) می‌نویسد و روی فایل
 * SQLite تغییری نمی‌دهد. پیش از انتقال از دیتابیس مقصد نسخه پشتیبان بگیرید.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
$sourceFile = SQLITE_DB_PATH;

function migrate_out(string $text, bool $isCli): void
{
    echo $isCli ? $text . PHP_EOL : '<div>' . e($text) . '</div>';
}

/* ---------- بررسی‌های اولیه ---------- */
$errors = [];
if (!is_file($sourceFile)) {
    $errors[] = 'فایل پایگاه داده SQLite پیدا نشد: ' . $sourceFile;
}
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    $errors[] = 'افزونه pdo_mysql روی این سرور فعال نیست.';
}

/* ---------- حالت مرورگر: احراز هویت مدیر ---------- */
$csrf = '';
if (!$isCli) {
    $user = Auth::user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo '<div style="font-family:Tahoma;direction:rtl;padding:40px;text-align:center">این ابزار فقط برای مدیر شرکت در دسترس است.</div>';
        exit;
    }
    $csrf = csrf_token();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>انتقال داده SQLite به MySQL | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-body">
<div class="login-wrap" style="max-width:720px">
  <div class="login-card">
    <div class="login-brand">
      <div class="brand-logo">⇄</div>
      <div>
        <h1>انتقال داده به پایگاه داده MySQL</h1>
        <p>مقصد: <code dir="ltr"><?= e(DB_NAME) ?></code> روی <code dir="ltr"><?= e(DB_HOST) ?></code> با کاربر <code dir="ltr"><?= e(DB_USER) ?></code></p>
      </div>
    </div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $err): ?><div>⚠️ <?= e($err) ?></div><?php endforeach; ?>
      <p class="small" style="margin:10px 0 0">ابتدا فایل <code>.env</code> و پشتیبانی از MySQL را بررسی کنید.</p>
    </div>

<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || $isCli):
    if (!$isCli) {
        $token = (string) (request_data()['csrf_token'] ?? '');
        if (!verify_csrf($token)) {
            echo '<div class="alert alert-error">توکن امنیتی نامعتبر است. صفحه را تازه‌سازی کنید.</div></div></div></body></html>';
            exit;
        }
    }

    @set_time_limit(300);
    echo '<div class="card" style="box-shadow:none;border:1px solid var(--border)">';
    $done = 0;
    $failed = 0;
    try {
        /* ۱) ایجاد اتصال مقصد MySQL (دیتابیس و جداول را خودکار می‌سازد) */
        $pdoTarget = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdoTarget->exec('SET NAMES ' . DB_CHARSET . ' COLLATE ' . DB_COLLATION);
        migrate_out('✅ اتصال به MySQL برقرار شد.', $isCli);

        /* ۲) ساخت ساختار جدول‌ها با همان منطق Schema (روی اتصال مقصد) */
        $schemaNeeded = [];
        $existing = $pdoTarget->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (Schema::REQUIRED_TABLES as $tbl) {
            if (!in_array($tbl, $existing, true)) $schemaNeeded[] = $tbl;
        }
        migrate_out('ℹ️ جداول موجود در مقصد: ' . (count($existing) ? implode(', ', $existing) : 'هیچ') . '', $isCli);
        if (!empty($schemaNeeded)) {
            migrate_out('ℹ️ این جداول هنوز در مقصد نیستند: ' . implode(', ', $schemaNeeded) . ' — با اولین باز شدن پنل خودکار ساخته می‌شوند (سپس دوباره این صفحه را اجرا کنید).', $isCli);
        }

        /* ۳) خواندن داده‌ها از SQLite و درج در MySQL */
        $pdoSource = new PDO('sqlite:' . $sourceFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // ترتیب درج مطابق کلیدهای خارجی است
        $order = Schema::REQUIRED_TABLES;
        $totalRows = 0;
        $pdoTarget->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($order as $table) {
            if (!in_array($table, $existing, true)) {
                migrate_out('⏭️ جدول ' . $table . ' در مقصد موجود نیست؛ رد شد.', $isCli);
                continue;
            }
            $rows = $pdoSource->query('SELECT * FROM "' . $table . '"')->fetchAll();
            if (empty($rows)) {
                migrate_out('➖ ' . $table . ': خالی است.', $isCli);
                continue;
            }
            // اگر مقصد داده دارد، برای جلوگیری از تکرار، جدول رد می‌شود
            $targetCount = (int) $pdoTarget->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            if ($targetCount > 0) {
                migrate_out('⏭️ ' . $table . ': در مقصد ' . fa_digits($targetCount) . ' ردیف دارد؛ برای جلوگیری از تکرار رد شد.', $isCli);
                continue;
            }
            $cols = array_keys($rows[0]);
            $colSql = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $cols));
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $pdoTarget->prepare('INSERT INTO `' . $table . '` (' . $colSql . ') VALUES (' . $ph . ')');
            $pdoTarget->beginTransaction();
            $inserted = 0;
            foreach ($rows as $row) {
                try {
                    $stmt->execute(array_values($row));
                    $inserted++;
                } catch (Throwable $e) {
                    $failed++;
                    migrate_out('⚠️ خطا در ' . $table . ': ' . $e->getMessage(), $isCli);
                }
            }
            $pdoTarget->commit();
            $done += $inserted;
            $totalRows += $inserted;
            migrate_out('✅ ' . $table . ': ' . fa_digits($inserted) . ' ردیف منتقل شد.', $isCli);
        }
        $pdoTarget->exec('SET FOREIGN_KEY_CHECKS=1');

        migrate_out('', $isCli);
        migrate_out('🎉 انتقال پایان یافت — مجموع ردیف‌های منتقل‌شده: ' . fa_digits($done) . ($failed > 0 ? ' | خطا: ' . fa_digits($failed) : ''), $isCli);
        if ($done > 0) {
            migrate_out('حالا مقدار DB_DRIVER را در فایل .env روی mysql بگذارید تا پنل همیشه از MySQL استفاده کند.', $isCli);
        }
    } catch (Throwable $e) {
        migrate_out('❌ خطا: ' . $e->getMessage(), $isCli);
    }
    echo '</div>';
    if (!$isCli) {
        echo '<div style="margin-top:14px;display:flex;gap:8px"><a class="btn btn-primary" href="index.php">بازگشت به پنل</a><a class="btn" href="migrate.php">اجرای مجدد</a></div>';
    }

?>
<?php else: ?>
    <div class="alert alert-info">
      این ابزار داده‌های موجود در فایل SQLite محلی را به پایگاه داده MySQL مقصد
      (<code dir="ltr"><?= e(DB_NAME) ?></code>) منتقل می‌کند.
    </div>
    <ul class="small" style="line-height:2">
      <li>جداول و ساختار فارسی مقصد باید از قبل ساخته شده باشند (یک‌بار پنل را باز کنید).</li>
      <li>هر جدولی که در مقصد داده دارد، رد می‌شود تا داده تکراری ایجاد نشود.</li>
      <li>فایل SQLite اصلی حذف یا تغییر داده نمی‌شود.</li>
      <li>پیش از اجرا از دیتابیس MySQL نسخه پشتیبان بگیرید.</li>
    </ul>
    <form method="post" action="migrate.php">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <button type="submit" class="btn btn-primary btn-block btn-lg">🚚 شروع انتقال داده</button>
    </form>
<?php endif; ?>

    <div class="login-foot">
      <span>فایل منبع: <code dir="ltr">storage/database/company_panel.sqlite</code></span>
      <span><?= e(fa_digits(APP_VERSION)) ?></span>
    </div>
  </div>
</div>
</body>
</html>
