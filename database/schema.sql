-- ============================================================================
-- اسکریپت کامل پایگاه داده SQL پنل مدیریت شرکت و تسک‌ها (همکار پلاس)
-- پشتیبانی کامل از زبان فارسی با کدگذاری utf8mb4 و مرتب‌سازی utf8mb4_persian_ci
-- توجه: اجرای دستی این فایل الزامی نیست؛ سیستم در اولین اجرا تمام جداول را
-- در صورت عدم وجود به صورت کاملاً خودکار ایجاد می‌کند (includes/Schema.php).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `company_panel`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_persian_ci;

USE `company_panel`;
SET NAMES utf8mb4 COLLATE utf8mb4_persian_ci;

-- ۱. جدول تنظیمات شرکت و هوش مصنوعی
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(191) NOT NULL,
    `setting_value` MEDIUMTEXT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۲. جدول واحدهای سازمانی شرکت
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `description` VARCHAR(500) NULL DEFAULT '',
    `color` VARCHAR(20) NOT NULL DEFAULT '#0d9488',
    `manager_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۳. جدول مدیران، سرپرستان و کارمندان
CREATE TABLE IF NOT EXISTS `users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۴. جدول پروژه‌های شرکت
CREATE TABLE IF NOT EXISTS `projects` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۵. جدول تسک‌ها و وظایف (دستی + صوتی هوشمند)
CREATE TABLE IF NOT EXISTS `tasks` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۶. جدول زیرتسک‌ها و چک‌لیست
CREATE TABLE IF NOT EXISTS `task_subtasks` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۷. جدول نظرات و پیام‌های صوتی روی تسک‌ها
CREATE TABLE IF NOT EXISTS `task_comments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۸. جدول فایل‌های پیوست تسک
CREATE TABLE IF NOT EXISTS `task_attachments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۹. جدول تایمر و ثبت ساعت کاری تسک‌ها
CREATE TABLE IF NOT EXISTS `task_time_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۱۰. جدول حضور و غیاب روزانه
CREATE TABLE IF NOT EXISTS `attendance` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۱۱. جدول درخواست‌های مرخصی و مأموریت
CREATE TABLE IF NOT EXISTS `leave_requests` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۱۲. جدول اطلاعیه‌های داخلی شرکت
CREATE TABLE IF NOT EXISTS `announcements` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۱۳. جدول اعلان‌های کاربران
CREATE TABLE IF NOT EXISTS `notifications` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۱۴. جدول تاریخچه فعالیت‌ها
CREATE TABLE IF NOT EXISTS `activity_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
