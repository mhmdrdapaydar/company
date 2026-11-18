<?php
declare(strict_types=1);

/**
 * مدیریت خودکار ساختار جداول SQL و داده‌های پایه شرکت
 * در هر بار اجرا بررسی می‌کند اگر حتی یکی از جداول ایجاد نشده بود، بلافاصله آن را با
 * کدگذاری فارسی کامل (utf8mb4_persian_ci در MySQL و UTF-8 در SQLite) ایجاد نماید.
 */
final class Schema
{
    public const REQUIRED_TABLES = [
        'settings',
        'departments',
        'users',
        'projects',
        'tasks',
        'task_subtasks',
        'task_comments',
        'task_attachments',
        'task_time_logs',
        'attendance',
        'leave_requests',
        'announcements',
        'notifications',
        'push_subscriptions',
        'push_jobs',
        'activity_logs',
    ];

    /**
     * بررسی وجود تمام جداول و ایجاد خودکار جداول ناموجود + مقداردهی اولیه در نصب اول
     */
    public static function ensureInitialized(): array
    {
        $existing = Database::listTables();
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $existing));

        $created = [];
        if (!empty($missing)) {
            if (Database::isMysql()) {
                self::createMysqlTables();
            } else {
                self::createSqliteTables();
            }
            $created = $missing;
        }

        // همیشه اطمینان حاصل می‌کنیم حداقل یک مدیر شرکت در سیستم وجود داشته باشد
        $seeded = self::seedIfEmpty();

        return [
            'missing_created' => $created,
            'seeded'          => $seeded,
        ];
    }

    /**
     * ایجاد تمام ۱۴ جدول در MySQL / MariaDB با موتور InnoDB و کدگذاری فارسی utf8mb4_persian_ci
     */
    private static function createMysqlTables(): void
    {
        $pdo = Database::pdo();
        $tableOpts = 'ENGINE=InnoDB DEFAULT CHARSET=' . DB_CHARSET . ' COLLATE=' . DB_COLLATION;

        $statements = [
            // ۱. تنظیمات سیستم و هوش مصنوعی
            "CREATE TABLE IF NOT EXISTS `settings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `setting_key` VARCHAR(191) NOT NULL,
                `setting_value` MEDIUMTEXT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_settings_key` (`setting_key`)
            ) {$tableOpts}",

            // ۲. واحدهای سازمانی شرکت
            "CREATE TABLE IF NOT EXISTS `departments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `description` VARCHAR(500) NULL DEFAULT '',
                `color` VARCHAR(20) NOT NULL DEFAULT '#0d9488',
                `manager_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_departments_name` (`name`)
            ) {$tableOpts}",

            // ۳. کاربران (مدیر شرکت، سرپرستان و کارمندان)
            "CREATE TABLE IF NOT EXISTS `users` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `employee_code` VARCHAR(50) NOT NULL,
                `username` VARCHAR(100) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `full_name` VARCHAR(191) NOT NULL,
                `email` VARCHAR(191) NULL DEFAULT '',
                `phone` VARCHAR(30) NULL DEFAULT '',
                `role` VARCHAR(20) NOT NULL DEFAULT 'employee',
                `position_title` VARCHAR(150) NULL DEFAULT '',
                `department_id` INT UNSIGNED NULL DEFAULT NULL,
                `avatar_color` VARCHAR(20) NOT NULL DEFAULT '#0d9488',
                `hire_date` DATE NULL DEFAULT NULL,
                `bio` TEXT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `last_login_at` DATETIME NULL DEFAULT NULL,
                `created_by` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_users_username` (`username`),
                UNIQUE KEY `uq_users_emp_code` (`employee_code`),
                KEY `idx_users_department` (`department_id`),
                KEY `idx_users_role_active` (`role`, `is_active`),
                CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
            ) {$tableOpts}",

            // ۴. پروژه‌های شرکت
            "CREATE TABLE IF NOT EXISTS `projects` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(191) NOT NULL,
                `code` VARCHAR(50) NOT NULL,
                `description` TEXT NULL,
                `color` VARCHAR(20) NOT NULL DEFAULT '#0284c7',
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `department_id` INT UNSIGNED NULL DEFAULT NULL,
                `owner_id` INT UNSIGNED NULL DEFAULT NULL,
                `start_date` DATE NULL DEFAULT NULL,
                `due_date` DATE NULL DEFAULT NULL,
                `created_by` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_projects_code` (`code`),
                KEY `idx_projects_status` (`status`),
                KEY `idx_projects_dept` (`department_id`),
                CONSTRAINT `fk_projects_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_projects_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) {$tableOpts}",

            // ۵. تسک‌ها و وظایف (دستی و صوتی با هوش مصنوعی)
            "CREATE TABLE IF NOT EXISTS `tasks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `task_code` VARCHAR(50) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `description` MEDIUMTEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'todo',
                `priority` VARCHAR(20) NOT NULL DEFAULT 'medium',
                `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `project_id` INT UNSIGNED NULL DEFAULT NULL,
                `department_id` INT UNSIGNED NULL DEFAULT NULL,
                `assigned_to` INT UNSIGNED NOT NULL,
                `created_by` INT UNSIGNED NOT NULL,
                `start_date` DATE NULL DEFAULT NULL,
                `due_date` DATE NULL DEFAULT NULL,
                `due_time` VARCHAR(10) NULL DEFAULT NULL,
                `estimated_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
                `recurrence` VARCHAR(20) NOT NULL DEFAULT 'none',
                `category` VARCHAR(80) NULL DEFAULT '',
                `tags` VARCHAR(500) NULL DEFAULT '',
                `created_via` VARCHAR(20) NOT NULL DEFAULT 'manual',
                `voice_file` VARCHAR(255) NULL DEFAULT NULL,
                `voice_transcript` MEDIUMTEXT NULL,
                `voice_duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
                `ai_provider` VARCHAR(80) NULL DEFAULT NULL,
                `review_note` TEXT NULL,
                `reviewed_by` INT UNSIGNED NULL DEFAULT NULL,
                `completed_at` DATETIME NULL DEFAULT NULL,
                `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tasks_code` (`task_code`),
                KEY `idx_tasks_assigned_status` (`assigned_to`, `status`),
                KEY `idx_tasks_due_date` (`due_date`),
                KEY `idx_tasks_project` (`project_id`),
                KEY `idx_tasks_creator` (`created_by`),
                KEY `idx_tasks_priority` (`priority`),
                CONSTRAINT `fk_tasks_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tasks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_tasks_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
            ) {$tableOpts}",

            // ۶. چک‌لیست و زیرتسک‌ها
            "CREATE TABLE IF NOT EXISTS `task_subtasks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `task_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `is_done` TINYINT(1) NOT NULL DEFAULT 0,
                `completed_by` INT UNSIGNED NULL DEFAULT NULL,
                `completed_at` DATETIME NULL DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_subtasks_task` (`task_id`),
                CONSTRAINT `fk_subtasks_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // ۷. نظرات متنی و صوتی روی تسک‌ها
            "CREATE TABLE IF NOT EXISTS `task_comments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `task_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `body` TEXT NOT NULL,
                `voice_file` VARCHAR(255) NULL DEFAULT NULL,
                `voice_duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_comments_task` (`task_id`),
                CONSTRAINT `fk_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // ۸. فایل‌های پیوست تسک
            "CREATE TABLE IF NOT EXISTS `task_attachments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `task_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `original_name` VARCHAR(255) NOT NULL,
                `stored_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
                `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_attachments_task` (`task_id`),
                CONSTRAINT `fk_attachments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_attachments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // ۹. زمان‌سنج و کارکرد ساعتی روی تسک‌ها
            "CREATE TABLE IF NOT EXISTS `task_time_logs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `task_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `started_at` DATETIME NOT NULL,
                `ended_at` DATETIME NULL DEFAULT NULL,
                `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
                `note` VARCHAR(300) NULL DEFAULT '',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_timelogs_task` (`task_id`),
                KEY `idx_timelogs_user` (`user_id`, `ended_at`),
                CONSTRAINT `fk_timelogs_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_timelogs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // ۱۰. حضور و غیاب روزانه کارمندان
            "CREATE TABLE IF NOT EXISTS `attendance` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `work_date` DATE NOT NULL,
                `check_in` DATETIME NOT NULL,
                `check_out` DATETIME NULL DEFAULT NULL,
                `total_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
                `work_mode` VARCHAR(20) NOT NULL DEFAULT 'office',
                `note` VARCHAR(300) NULL DEFAULT '',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_attendance_user_date` (`user_id`, `work_date`),
                CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // ۱۱. درخواست‌های مرخصی و مأموریت
            "CREATE TABLE IF NOT EXISTS `leave_requests` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `leave_type` VARCHAR(20) NOT NULL DEFAULT 'daily',
                `start_date` DATE NOT NULL,
                `end_date` DATE NOT NULL,
                `hours` DECIMAL(5,1) NOT NULL DEFAULT 0.0,
                `reason` TEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `reviewed_by` INT UNSIGNED NULL DEFAULT NULL,
                `review_note` VARCHAR(400) NULL DEFAULT '',
                `reviewed_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_leaves_user` (`user_id`),
                KEY `idx_leaves_status` (`status`),
                CONSTRAINT `fk_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // ۱۲. اطلاعیه‌ها و اعلانات داخلی شرکت
            "CREATE TABLE IF NOT EXISTS `announcements` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title` VARCHAR(220) NOT NULL,
                `body` TEXT NOT NULL,
                `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
                `department_id` INT UNSIGNED NULL DEFAULT NULL,
                `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT UNSIGNED NOT NULL,
                `expires_at` DATE NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_announcements_pinned` (`is_pinned`, `created_at`),
                CONSTRAINT `fk_announcements_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_announcements_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
            ) {$tableOpts}",

            // ۱۳. اعلان‌های شخصی کاربران
            "CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `actor_id` INT UNSIGNED NULL DEFAULT NULL,
                `type` VARCHAR(40) NOT NULL DEFAULT 'info',
                `title` VARCHAR(191) NOT NULL,
                `message` VARCHAR(500) NOT NULL,
                `entity_type` VARCHAR(40) NOT NULL DEFAULT 'task',
                `entity_id` INT UNSIGNED NULL DEFAULT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_notif_user_read` (`user_id`, `is_read`, `created_at`),
                CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // اشتراک‌های پوش و صف تحویل؛ هیچ کلید خصوصی VAPID در دیتابیس ذخیره نمی‌شود.
            "CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `endpoint_hash` CHAR(64) NOT NULL,
                `endpoint` TEXT NOT NULL,
                `p256dh` VARCHAR(255) NOT NULL,
                `auth_secret` VARCHAR(255) NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
                KEY `idx_push_user` (`user_id`),
                CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",
            "CREATE TABLE IF NOT EXISTS `push_jobs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `notification_id` INT UNSIGNED NOT NULL,
                `subscription_id` INT UNSIGNED NOT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `available_at` DATETIME NOT NULL,
                `lease_token` VARCHAR(64) NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `uq_push_job` (`notification_id`, `subscription_id`),
                KEY `idx_push_available` (`available_at`),
                CONSTRAINT `fk_push_job_notif` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_push_job_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `push_subscriptions` (`id`) ON DELETE CASCADE
            ) {$tableOpts}",

            // ۱۴. گزارش کامل فعالیت‌ها و تاریخچه سامانه
            "CREATE TABLE IF NOT EXISTS `activity_logs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NULL DEFAULT NULL,
                `action` VARCHAR(60) NOT NULL,
                `entity_type` VARCHAR(40) NOT NULL,
                `entity_id` INT UNSIGNED NULL DEFAULT NULL,
                `description` VARCHAR(500) NOT NULL,
                `ip_address` VARCHAR(45) NULL DEFAULT '',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_activity_created` (`created_at`),
                KEY `idx_activity_user` (`user_id`)
            ) {$tableOpts}",
        ];

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * ایجاد تمام ۱۴ جدول در SQLite با کدگذاری کامل UTF-8 فارسی
     */
    private static function createSqliteTables(): void
    {
        $pdo = Database::pdo();

        $statements = [
            "CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                setting_key TEXT NOT NULL UNIQUE,
                setting_value TEXT,
                updated_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS departments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT DEFAULT '',
                color TEXT NOT NULL DEFAULT '#0d9488',
                manager_id INTEGER NULL,
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_code TEXT NOT NULL UNIQUE,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                full_name TEXT NOT NULL,
                email TEXT DEFAULT '',
                phone TEXT DEFAULT '',
                role TEXT NOT NULL DEFAULT 'employee',
                position_title TEXT DEFAULT '',
                department_id INTEGER NULL REFERENCES departments(id) ON DELETE SET NULL,
                avatar_color TEXT NOT NULL DEFAULT '#0d9488',
                hire_date TEXT NULL,
                bio TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                last_login_at TEXT NULL,
                created_by INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                code TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                color TEXT NOT NULL DEFAULT '#0284c7',
                status TEXT NOT NULL DEFAULT 'active',
                department_id INTEGER NULL REFERENCES departments(id) ON DELETE SET NULL,
                owner_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                start_date TEXT NULL,
                due_date TEXT NULL,
                created_by INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_code TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                description TEXT NULL,
                status TEXT NOT NULL DEFAULT 'todo',
                priority TEXT NOT NULL DEFAULT 'medium',
                progress INTEGER NOT NULL DEFAULT 0,
                project_id INTEGER NULL REFERENCES projects(id) ON DELETE SET NULL,
                department_id INTEGER NULL REFERENCES departments(id) ON DELETE SET NULL,
                assigned_to INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                start_date TEXT NULL,
                due_date TEXT NULL,
                due_time TEXT NULL,
                estimated_minutes INTEGER NOT NULL DEFAULT 0,
                recurrence TEXT NOT NULL DEFAULT 'none',
                category TEXT DEFAULT '',
                tags TEXT DEFAULT '',
                created_via TEXT NOT NULL DEFAULT 'manual',
                voice_file TEXT NULL,
                voice_transcript TEXT NULL,
                voice_duration_sec INTEGER NOT NULL DEFAULT 0,
                ai_provider TEXT NULL,
                review_note TEXT NULL,
                reviewed_by INTEGER NULL,
                completed_at TEXT NULL,
                is_archived INTEGER NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS task_subtasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                is_done INTEGER NOT NULL DEFAULT 0,
                completed_by INTEGER NULL,
                completed_at TEXT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS task_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                body TEXT NOT NULL,
                voice_file TEXT NULL,
                voice_duration_sec INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS task_attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                original_name TEXT NOT NULL,
                stored_name TEXT NOT NULL,
                mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
                file_size INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS task_time_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                started_at TEXT NOT NULL,
                ended_at TEXT NULL,
                duration_minutes INTEGER NOT NULL DEFAULT 0,
                note TEXT DEFAULT '',
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS attendance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                work_date TEXT NOT NULL,
                check_in TEXT NOT NULL,
                check_out TEXT NULL,
                total_minutes INTEGER NOT NULL DEFAULT 0,
                work_mode TEXT NOT NULL DEFAULT 'office',
                note TEXT DEFAULT '',
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS leave_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                leave_type TEXT NOT NULL DEFAULT 'daily',
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                hours REAL NOT NULL DEFAULT 0,
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                reviewed_by INTEGER NULL,
                review_note TEXT DEFAULT '',
                reviewed_at TEXT NULL,
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS announcements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                priority TEXT NOT NULL DEFAULT 'normal',
                department_id INTEGER NULL REFERENCES departments(id) ON DELETE SET NULL,
                is_pinned INTEGER NOT NULL DEFAULT 0,
                created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                expires_at TEXT NULL,
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                actor_id INTEGER NULL,
                type TEXT NOT NULL DEFAULT 'info',
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                entity_type TEXT NOT NULL DEFAULT 'task',
                entity_id INTEGER NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                endpoint_hash TEXT NOT NULL UNIQUE,
                endpoint TEXT NOT NULL,
                p256dh TEXT NOT NULL,
                auth_secret TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS push_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                notification_id INTEGER NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
                subscription_id INTEGER NOT NULL REFERENCES push_subscriptions(id) ON DELETE CASCADE,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TEXT NOT NULL,
                lease_token TEXT NULL,
                created_at TEXT NOT NULL,
                UNIQUE(notification_id, subscription_id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_push_available ON push_jobs(available_at)",

            "CREATE TABLE IF NOT EXISTS activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NULL,
                description TEXT NOT NULL,
                ip_address TEXT DEFAULT '',
                created_at TEXT NOT NULL
            )",

            // ایندکس‌های بهینه‌سازی جستجو و فیلتر
            "CREATE INDEX IF NOT EXISTS idx_tasks_assigned_status ON tasks(assigned_to, status)",
            "CREATE INDEX IF NOT EXISTS idx_tasks_due_date ON tasks(due_date)",
            "CREATE INDEX IF NOT EXISTS idx_tasks_project ON tasks(project_id)",
            "CREATE INDEX IF NOT EXISTS idx_subtasks_task ON task_subtasks(task_id)",
            "CREATE INDEX IF NOT EXISTS idx_comments_task ON task_comments(task_id)",
            "CREATE INDEX IF NOT EXISTS idx_notif_user_read ON notifications(user_id, is_read, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_attendance_user_date ON attendance(user_id, work_date)",
        ];

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * مقداردهی اولیه حساب مدیر شرکت، واحدهای سازمانی و داده‌های نمونه کاربردی (فقط زمانی که جدول کاربران خالی است)
     */
    private static function seedIfEmpty(): bool
    {
        $userCount = (int) Database::fetchValue('SELECT COUNT(*) FROM users', [], 0);
        if ($userCount > 0) {
            return false;
        }

        $now       = now_datetime();
        $today     = today_date();
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow  = date('Y-m-d', strtotime('+1 day'));
        $in2Days   = date('Y-m-d', strtotime('+2 days'));
        $in4Days   = date('Y-m-d', strtotime('+4 days'));
        $in7Days   = date('Y-m-d', strtotime('+7 days'));
        $in20Days  = date('Y-m-d', strtotime('+20 days'));
        $ago30Days = date('Y-m-d', strtotime('-30 days'));
        $ago90Days = date('Y-m-d', strtotime('-90 days'));

        Database::transaction(static function () use (
            $now, $today, $yesterday, $tomorrow, $in2Days, $in4Days, $in7Days, $in20Days, $ago30Days, $ago90Days
        ): void {
            // تنظیمات پیش‌فرض شرکت و هوش مصنوعی رایگان
            $defaultSettings = [
                'company_name'            => 'شرکت فناوری نوآوران پارس',
                'company_slogan'          => 'سامانه یکپارچه مدیریت تیم، پروژه‌ها و دستیار صوتی هوشمند',
                'work_start_time'         => '08:30',
                'work_end_time'           => '17:00',
                'allow_employee_self_task'=> '1',
                'allow_employee_peer_task'=> '0',
                'ai_provider'             => AI_PROVIDER,
                'ai_auto_create'          => '0',
                'groq_api_key'            => GROQ_API_KEY,
                'gemini_api_key'          => GEMINI_API_KEY,
                'schema_version'          => APP_VERSION,
            ];
            foreach ($defaultSettings as $k => $v) {
                $exists = Database::fetchOne('SELECT id FROM settings WHERE setting_key = ?', [$k]);
                if (!$exists) {
                    Database::insert(
                        'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                        [$k, $v, $now]
                    );
                }
            }

            // واحدهای سازمانی پیش‌فرض شرکت
            $departmentsData = [
                ['مدیریت و راهبری',     'مدیریت عامل، برنامه‌ریزی کلان و تصمیم‌گیری‌های راهبردی شرکت', '#0f766e'],
                ['فنی و توسعه محصول',   'توسعه نرم‌افزار، زیرساخت، طراحی محصول و کنترل کیفیت',        '#0284c7'],
                ['فروش و بازاریابی',    'فروش سازمانی، دیجیتال مارکتینگ، مذاکرات و ارتباط با مشتریان', '#7c3aed'],
                ['مالی و منابع انسانی', 'حسابداری، حقوق و دستمزد، قراردادها و امور اداری پرسنل',       '#d97706'],
                ['پشتیبانی مشتریان',    'پاسخگویی به مشتریان، استقرار سیستم و پیگیری تیکت‌ها',         '#e11d48'],
            ];
            $deptIds = [];
            foreach ($departmentsData as [$dName, $dDesc, $dColor]) {
                $existingDept = Database::fetchOne('SELECT id FROM departments WHERE name = ?', [$dName]);
                if ($existingDept) {
                    $deptIds[] = (int) $existingDept['id'];
                } else {
                    $deptIds[] = Database::insert(
                        'INSERT INTO departments (name, description, color, created_at) VALUES (?, ?, ?, ?)',
                        [$dName, $dDesc, $dColor, $now]
                    );
                }
            }

            // ایجاد مدیر اصلی شرکت
            $adminId = Database::insert(
                'INSERT INTO users (employee_code, username, password_hash, full_name, email, phone, role, position_title, department_id, avatar_color, hire_date, bio, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [
                    'EMP-1001',
                    'admin',
                    password_hash('admin123', PASSWORD_DEFAULT),
                    'محمدرضا پایدار',
                    'admin@company.ir',
                    '09121001001',
                    'admin',
                    'مدیر عامل شرکت',
                    $deptIds[0],
                    '#0f766e',
                    $ago90Days,
                    'مدیر عامل و مسئول کل سامانه مدیریت تسک‌ها و تیم شرکت',
                    $now,
                    $now,
                ]
            );

            Database::execute('UPDATE departments SET manager_id = ? WHERE id = ?', [$adminId, $deptIds[0]]);

            if (!DB_SEED_DEMO) {
                return;
            }

            // معرفی کارمندان نمونه برای تست فوری سناریوهای مدیر و کارمند
            $emp1Id = Database::insert(
                'INSERT INTO users (employee_code, username, password_hash, full_name, email, phone, role, position_title, department_id, avatar_color, hire_date, bio, is_active, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [
                    'EMP-1002', 'sara', password_hash('123456', PASSWORD_DEFAULT),
                    'سارا محمدی', 'sara@company.ir', '09122002002', 'manager',
                    'سرپرست تیم فنی', $deptIds[1], '#0284c7', $ago90Days,
                    'مسئول معماری نرم‌افزار و راهبری تیم توسعه', $adminId, $now, $now,
                ]
            );
            $emp2Id = Database::insert(
                'INSERT INTO users (employee_code, username, password_hash, full_name, email, phone, role, position_title, department_id, avatar_color, hire_date, bio, is_active, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [
                    'EMP-1003', 'ali', password_hash('123456', PASSWORD_DEFAULT),
                    'علی رضایی', 'ali@company.ir', '09123003003', 'employee',
                    'برنامه‌نویس ارشد وب', $deptIds[1], '#4f46e5', $ago30Days,
                    'توسعه‌دهنده بک‌اند و پایگاه داده', $adminId, $now, $now,
                ]
            );
            $emp3Id = Database::insert(
                'INSERT INTO users (employee_code, username, password_hash, full_name, email, phone, role, position_title, department_id, avatar_color, hire_date, bio, is_active, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [
                    'EMP-1004', 'mina', password_hash('123456', PASSWORD_DEFAULT),
                    'مینا کریمی', 'mina@company.ir', '09124004004', 'employee',
                    'کارشناس فروش و قراردادها', $deptIds[2], '#7c3aed', $ago30Days,
                    'پیگیری مشتریان سازمانی، دموی محصول و تنظیم قراردادها', $adminId, $now, $now,
                ]
            );
            $emp4Id = Database::insert(
                'INSERT INTO users (employee_code, username, password_hash, full_name, email, phone, role, position_title, department_id, avatar_color, hire_date, bio, is_active, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [
                    'EMP-1005', 'amir', password_hash('123456', PASSWORD_DEFAULT),
                    'امیر حسینی', 'amir@company.ir', '09125005005', 'employee',
                    'حسابدار و کارشناس مالی', $deptIds[3], '#d97706', $ago30Days,
                    'تنظیم اسناد مالی، فاکتورهای رسمی و گزارش‌های ماهانه', $adminId, $now, $now,
                ]
            );

            Database::execute('UPDATE departments SET manager_id = ? WHERE id = ?', [$emp1Id, $deptIds[1]]);

            // پروژه‌های نمونه
            $proj1Id = Database::insert(
                'INSERT INTO projects (name, code, description, color, status, department_id, owner_id, start_date, due_date, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'بازطراحی پورتال مشتریان', 'PRJ-101',
                    'ارتقای تجربه کاربری، بهینه‌سازی سرعت و افزودن داشبورد گزارش‌گیری شمسی برای مشتریان سازمانی',
                    '#0284c7', 'active', $deptIds[1], $emp1Id, $ago30Days, $in20Days, $adminId, $now, $now,
                ]
            );
            $proj2Id = Database::insert(
                'INSERT INTO projects (name, code, description, color, status, department_id, owner_id, start_date, due_date, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'کمپین فروش پاییزه شرکت', 'PRJ-102',
                    'جذب مشتریان سازمانی جدید، برگزاری جلسات معارفه و نهایی‌سازی قراردادهای فصل پاییز',
                    '#7c3aed', 'active', $deptIds[2], $adminId, $ago30Days, $in20Days, $adminId, $now, $now,
                ]
            );
            $proj3Id = Database::insert(
                'INSERT INTO projects (name, code, description, color, status, department_id, owner_id, start_date, due_date, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'بستن حساب‌ها و گزارش مالی ماهانه', 'PRJ-103',
                    'بررسی صورت‌حساب‌ها، تسویه تنخواه، لیست بیمه و گزارش عملکرد مالی به مدیریت',
                    '#d97706', 'active', $deptIds[3], $adminId, $yesterday, $in7Days, $adminId, $now, $now,
                ]
            );

            // تسک‌های نمونه متنوع (شامل تسک محول‌شده توسط مدیر، تسک شخصی کارمند، و تسک ایجادشده با ویس هوش مصنوعی)
            $t1 = Database::insert(
                'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, category, tags, created_via, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'TSK-1001',
                    'پیاده‌سازی بخش گزارش‌گیری لحظه‌ای و خروجی اکسل فارسی',
                    'گزارش فروش و عملکرد پروژه‌ها باید با تاریخ شمسی دقیق و پشتیبانی کامل فونت فارسی آماده تحویل شود.',
                    'in_progress', 'high', 65, $proj1Id, $deptIds[1], $emp2Id, $adminId,
                    $yesterday, $today, '16:00', 240, 'فنی', 'گزارش,بک‌اند,فوری', 'manual', $now, $now,
                ]
            );

            $t2 = Database::insert(
                'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, category, tags, created_via, voice_transcript, voice_duration_sec, ai_provider, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'TSK-1002',
                    'جلسه بررسی قرارداد همکاری با شرکت داده‌پردازان',
                    'ایجادشده از طریق فرمان صوتی مدیر: هماهنگی جلسه حضوری، آماده‌سازی پیش‌نویس قرارداد و جدول تعرفه‌های سازمانی.',
                    'todo', 'urgent', 20, $proj2Id, $deptIds[2], $emp3Id, $adminId,
                    $today, $tomorrow, '10:30', 90, 'جلسه و قرارداد', 'قرارداد,فروش,ویس', 'voice_ai',
                    'برای فردا ساعت ده و نیم صبح جلسه بررسی قرارداد همکاری با شرکت داده‌پردازان رو برای مینا کریمی با اولویت فوری ثبت کن',
                    11, 'دستیار هوشمند فارسی', $now, $now,
                ]
            );

            $t3 = Database::insert(
                'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, category, tags, created_via, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'TSK-1003',
                    'ارسال لیست بیمه و تسویه فاکتورهای پیمانکاران',
                    'بررسی نهایی اسناد هزینه‌ای ماه جاری و بارگذاری مستندات تاییدشده در پرونده مالی.',
                    'review', 'high', 90, $proj3Id, $deptIds[3], $emp4Id, $adminId,
                    $yesterday, $in2Days, '14:00', 180, 'مالی', 'مالی,بیمه,فاکتور', 'manual', $now, $now,
                ]
            );

            $t4 = Database::insert(
                'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, category, tags, created_via, completed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'TSK-1004',
                    'بهینه‌سازی امنیت ورود کاربران و پشتیبان‌گیری خودکار دیتابیس',
                    'تست کامل سیستم احراز هویت، محدودسازی دسترسی فایل‌ها و زمان‌بندی بکاپ روزانه پایگاه داده.',
                    'done', 'medium', 100, $proj1Id, $deptIds[1], $emp1Id, $emp1Id,
                    $yesterday, $today, '11:00', 150, 'فنی', 'امنیت,دیتابیس', 'manual', $now, $now, $now,
                ]
            );

            Database::insert(
                'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, category, tags, created_via, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'TSK-1005',
                    'برگزاری جلسه هماهنگی هفتگی مدیران واحدها',
                    'مرور شاخص‌های عملکرد هفتگی، بررسی موانع پروژه‌های فعال و برنامه‌ریزی اسپرینت بعدی.',
                    'todo', 'medium', 0, $proj1Id, $deptIds[0], $adminId, $adminId,
                    $today, $in4Days, '09:30', 60, 'جلسه', 'مدیریت,هماهنگی', 'manual', $now, $now,
                ]
            );

            Database::insert(
                'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, category, tags, created_via, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'TSK-1006',
                    'به‌روزرسانی مستندات فنی API پورتال مشتریان',
                    'تسک تعریف‌شده توسط خود کارمند برای تکمیل راهنمای سرویس‌ها و نمونه درخواست‌ها.',
                    'todo', 'low', 0, $proj1Id, $deptIds[1], $emp2Id, $emp2Id,
                    $today, $in7Days, '15:00', 120, 'مستندسازی', 'مستندات,API', 'manual', $now, $now,
                ]
            );

            Database::insert(
                'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, category, tags, created_via, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'TSK-1007',
                    'پیگیری پیش‌فاکتورهای ارسال‌شده هفته گذشته',
                    'تماس با سه مشتری سازمانی و ثبت نتیجه مذاکره در گزارش کمپین پاییزه.',
                    'in_progress', 'high', 50, $proj2Id, $deptIds[2], $emp3Id, $adminId,
                    $ago30Days, $yesterday, '12:00', 90, 'فروش', 'پیگیری,پیش‌فاکتور', 'manual', $now, $now,
                ]
            );

            // چک‌لیست زیرتسک‌ها
            $subtasks = [
                [$t1, 'طراحی کوئری‌های آماری پایگاه داده', 1, 1],
                [$t1, 'تبدیل تاریخ‌ها به تقویم شمسی و اعداد فارسی', 1, 2],
                [$t1, 'افزودن خروجی اکسل با کدگذاری UTF-8 فارسی', 0, 3],
                [$t2, 'آماده‌سازی فایل ارائه خدمات شرکت', 1, 1],
                [$t2, 'چاپ دو نسخه پیش‌نویس قرارداد حقوقی', 0, 2],
                [$t2, 'هماهنگی اتاق جلسات طبقه سوم', 0, 3],
                [$t3, 'کنترل جمع مبالغ فاکتورها با رسید بانکی', 1, 1],
                [$t3, 'تأیید نهایی مدیر شرکت', 0, 2],
            ];
            foreach ($subtasks as [$tid, $sTitle, $done, $ord]) {
                Database::insert(
                    'INSERT INTO task_subtasks (task_id, title, is_done, completed_by, completed_at, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$tid, $sTitle, $done, $done ? $adminId : null, $done ? $now : null, $ord, $now]
                );
            }

            // کامنت نمونه
            Database::insert(
                'INSERT INTO task_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)',
                [$t1, $emp2Id, 'بخش کوئری‌ها و تبدیل تاریخ شمسی کامل شد؛ تا ساعت ۱۶ امروز خروجی اکسل هم نهایی و ارسال می‌شود.', $now]
            );
            Database::insert(
                'INSERT INTO task_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)',
                [$t3, $emp4Id, 'تمامی مدارک مالی بررسی و بارگذاری شد. لطفاً بازبینی و تأیید نهایی بفرمایید.', $now]
            );

            // ثبت کارکرد زمانی نمونه
            Database::insert(
                'INSERT INTO task_time_logs (task_id, user_id, started_at, ended_at, duration_minutes, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$t1, $emp2Id, $now, $now, 135, 'پیاده‌سازی بخش آمار و نمودارها', $now]
            );
            Database::insert(
                'INSERT INTO task_time_logs (task_id, user_id, started_at, ended_at, duration_minutes, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$t4, $emp1Id, $now, $now, 150, 'تست امنیتی و راه‌اندازی بکاپ خودکار', $now]
            );

            // اطلاعیه رسمی شرکت
            Database::insert(
                'INSERT INTO announcements (title, body, priority, department_id, is_pinned, created_by, created_at) VALUES (?, ?, ?, NULL, 1, ?, ?)',
                [
                    'راه‌اندازی دستیار صوتی هوش مصنوعی در پنل شرکت',
                    'همکاران گرامی، از امروز می‌توانید با کلیک روی دکمه «تسک صوتی هوشمند» صدای خود را به فارسی ضبط کنید تا هوش مصنوعی به صورت خودکار موضوع، تاریخ شمسی، ساعت و اولویت تسک را تشخیص داده و در تقویم کاری شما ثبت نماید.',
                    'important',
                    $adminId,
                    $now,
                ]
            );

            // اعلان نمونه برای مدیر و کارمند
            Database::insert(
                'INSERT INTO notifications (user_id, actor_id, type, title, message, entity_type, entity_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [$adminId, $emp4Id, 'task_review', 'تسک آماده بررسی مدیر', 'امیر حسینی تسک «ارسال لیست بیمه و تسویه فاکتورهای پیمانکاران» را برای تأیید نهایی ارسال کرد.', 'task', $t3, $now]
            );
            Database::insert(
                'INSERT INTO notifications (user_id, actor_id, type, title, message, entity_type, entity_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [$emp2Id, $adminId, 'task_assigned', 'تسک جدید از طرف مدیر شرکت', 'محمدرضا پایدار تسک «پیاده‌سازی بخش گزارش‌گیری لحظه‌ای و خروجی اکسل فارسی» را به شما محول کرد.', 'task', $t1, $now]
            );

            // گزارش فعالیت اولیه
            Database::insert(
                'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$adminId, 'system_init', 'system', 1, 'پایگاه داده و جداول سامانه با پشتیبانی کامل زبان فارسی به صورت خودکار ایجاد و راه‌اندازی شد.', '127.0.0.1', $now]
            );
        });

        return true;
    }
}
