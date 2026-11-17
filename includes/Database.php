<?php
declare(strict_types=1);

/**
 * لایه اتصال به پایگاه داده SQL با PDO
 * - پشتیبانی کامل از MySQL / MariaDB با کدگذاری فارسی utf8mb4 و مرتب‌سازی utf8mb4_persian_ci
 * - ایجاد خودکار دیتابیس در صورت عدم وجود (و سازگاری با هاست‌های اشتراکی cPanel/DirectAdmin)
 * - پشتیبانی جایگزین خودکار از SQLite (با UTF-8 کامل فارسی) در حالت auto یا sqlite
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static string $activeDriver = '';
    private static ?string $mysqlFallbackReason = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $mode = strtolower(trim(DB_DRIVER));
        $available = PDO::getAvailableDrivers();

        if ($mode === 'mysql') {
            if (!in_array('mysql', $available, true)) {
                throw new RuntimeException('افزونه pdo_mysql در PHP فعال نیست. لطفاً آن را در php.ini فعال کنید.');
            }
            self::$pdo = self::connectMysql();
            self::$activeDriver = 'mysql';
            return self::$pdo;
        }

        if ($mode === 'sqlite') {
            if (!in_array('sqlite', $available, true)) {
                throw new RuntimeException('افزونه pdo_sqlite در PHP فعال نیست.');
            }
            self::$pdo = self::connectSqlite();
            self::$activeDriver = 'sqlite';
            return self::$pdo;
        }

        // حالت auto: ابتدا تلاش برای اتصال به MySQL/MariaDB؛ در صورت عدم دسترسی، راه‌اندازی خودکار SQLite
        if (in_array('mysql', $available, true)) {
            try {
                self::$pdo = self::connectMysql(2);
                self::$activeDriver = 'mysql';
                return self::$pdo;
            } catch (Throwable $e) {
                self::$mysqlFallbackReason = $e->getMessage();
            }
        } else {
            self::$mysqlFallbackReason = 'افزونه pdo_mysql نصب نیست.';
        }

        if (in_array('sqlite', $available, true)) {
            self::$pdo = self::connectSqlite();
            self::$activeDriver = 'sqlite';
            return self::$pdo;
        }

        throw new RuntimeException('هیچ درایور پایگاه داده SQL فعالی یافت نشد: ' . (self::$mysqlFallbackReason ?? ''));
    }

    /**
     * اتصال به MySQL / MariaDB و ایجاد خودکار دیتابیس با utf8mb4_persian_ci در صورت عدم وجود
     */
    private static function connectMysql(int $timeout = 5): PDO
    {
        $host      = DB_HOST;
        $port      = DB_PORT;
        $dbName    = preg_replace('/[^a-zA-Z0-9_\-]/', '', DB_NAME) ?: 'company_panel';
        $charset   = DB_CHARSET;
        $collation = DB_COLLATION;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => $timeout,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$charset} COLLATE {$collation}";
        }

        // ابتدا تلاش برای اتصال مستقیم به دیتابیس (سازگار با cPanel که دیتابیس از قبل ساخته شده)
        $dsnWithDb = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
        try {
            $pdo = new PDO($dsnWithDb, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // کد خطای 1049: دیتابیس وجود ندارد -> دیتابیس را خودکار با کدگذاری فارسی می‌سازیم
            $msg = $e->getMessage();
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code === 1049 || stripos($msg, 'Unknown database') !== false) {
                $dsnServer = "mysql:host={$host};port={$port};charset={$charset}";
                $serverPdo = new PDO($dsnServer, DB_USER, DB_PASS, $options);
                $serverPdo->exec(
                    "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}"
                );
                $serverPdo = null;
                $pdo = new PDO($dsnWithDb, DB_USER, DB_PASS, $options);
            } else {
                throw $e;
            }
        }

        // تضمین کامل پشتیبانی فارسی و منطقه زمانی ایران روی نشست MySQL
        $pdo->exec("SET NAMES {$charset} COLLATE {$collation}");
        $pdo->exec("SET CHARACTER SET {$charset}");
        try {
            $pdo->exec("ALTER DATABASE `{$dbName}` CHARACTER SET = {$charset} COLLATE = {$collation}");
        } catch (Throwable) {
            // در هاست‌های اشتراکی ممکن است مجوز ALTER DATABASE نباشد؛ جداول مستقل تنظیم می‌شوند
        }

        return $pdo;
    }

    /**
     * اتصال به SQLite با کدگذاری کامل UTF-8 فارسی و کلید خارجی فعال
     */
    private static function connectSqlite(): PDO
    {
        $dir = dirname(SQLITE_DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO('sqlite:' . SQLITE_DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA encoding = "UTF-8"');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    public static function driver(): string
    {
        self::pdo();
        return self::$activeDriver;
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    public static function fallbackReason(): ?string
    {
        return self::$mysqlFallbackReason;
    }

    /**
     * اجرای کوئری با پارامترهای امن Prepared Statement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    public static function fetchValue(string $sql, array $params = [], mixed $default = null): mixed
    {
        $row = self::query($sql, $params)->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !array_key_exists(0, $row)) {
            return $default;
        }
        return $row[0];
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * درج رکورد و بازگرداندن شناسه (ID) جدید
     */
    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * اجرای تراکنش امن SQL
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * فهرست جداول فعلی موجود در پایگاه داده
     * @return string[]
     */
    public static function listTables(): array
    {
        if (self::isMysql()) {
            $rows = self::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        } else {
            $rows = self::pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_NUM);
        }
        return array_map(static fn(array $r): string => (string) $r[0], $rows);
    }

    /**
     * دریافت اطلاعات وضعیت پایگاه داده و کدگذاری فارسی برای پنل تنظیمات
     */
    public static function statusInfo(): array
    {
        $pdo = self::pdo();
        $driver = self::$activeDriver;
        $tables = self::listTables();
        $tableStats = [];

        $persianTableNames = [
            'settings'         => 'تنظیمات سیستم',
            'departments'      => 'واحدهای سازمانی',
            'users'            => 'مدیران و کارمندان',
            'projects'         => 'پروژه‌های شرکت',
            'tasks'            => 'تسک‌ها و وظایف',
            'task_subtasks'    => 'چک‌لیست و زیرتسک‌ها',
            'task_comments'    => 'نظرات و گفتگوهای تسک',
            'task_attachments' => 'فایل‌های پیوست',
            'task_time_logs'   => 'ثبت ساعت کاری تسک‌ها',
            'attendance'       => 'حضور و غیاب روزانه',
            'leave_requests'   => 'درخواست‌های مرخصی',
            'announcements'    => 'اطلاعیه‌های شرکت',
            'notifications'    => 'اعلان‌های کاربران',
            'activity_logs'    => 'گزارش فعالیت‌ها',
        ];

        foreach ($persianTableNames as $tbl => $label) {
            $exists = in_array($tbl, $tables, true);
            $count = 0;
            if ($exists) {
                $safeTbl = self::isMysql() ? "`{$tbl}`" : "\"{$tbl}\"";
                $count = (int) self::fetchValue("SELECT COUNT(*) FROM {$safeTbl}", [], 0);
            }
            $tableStats[] = [
                'name'     => $tbl,
                'label'    => $label,
                'exists'   => $exists,
                'rows'     => $count,
                'rows_fa'  => fa_digits($count),
            ];
        }

        if ($driver === 'mysql') {
            $version   = (string) self::fetchValue('SELECT VERSION()', [], '');
            $charset   = (string) self::fetchValue('SELECT @@character_set_database', [], DB_CHARSET);
            $collation = (string) self::fetchValue('SELECT @@collation_database', [], DB_COLLATION);
            $dbLabel   = 'MySQL / MariaDB (' . DB_NAME . ')';
        } else {
            $version   = (string) self::fetchValue('SELECT sqlite_version()', [], '');
            $charset   = (string) self::fetchValue('PRAGMA encoding', [], 'UTF-8');
            $collation = 'UTF-8 (پشتیبانی کامل یونیکد و فارسی)';
            $dbLabel   = 'SQLite SQL (' . basename(SQLITE_DB_PATH) . ')';
        }

        return [
            'driver'        => $driver,
            'engine_label'  => $dbLabel,
            'version'       => $version,
            'charset'       => $charset,
            'collation'     => $collation,
            'auto_schema'   => true,
            'persian_ready' => true,
            'configured_as' => DB_DRIVER,
            'mysql_host'    => DB_HOST . ':' . DB_PORT,
            'mysql_db'      => DB_NAME,
            'tables_count'  => count($tableStats),
            'tables'        => $tableStats,
        ];
    }
}
