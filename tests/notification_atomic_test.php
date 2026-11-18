<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
if (Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { echo "SKIP: SQLite-only failure injection\n"; exit(0); }
$before = (int)Database::fetchValue('SELECT COUNT(*) FROM announcements');
$user = (int)Database::fetchValue('SELECT id FROM users ORDER BY id LIMIT 1');
Database::execute("CREATE TEMP TRIGGER test_fail_notification BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'test failure'); END");
$caught = false;
try {
    Database::transaction(function () use ($user): void {
        Database::insert("INSERT INTO announcements (title, body, priority, is_pinned, created_by, created_at) VALUES (?, ?, 'normal', 0, ?, ?)", ['temporary', 'temporary', $user, now_datetime()]);
        create_notification($user, null, 'announcement', 'test', 'test', 'announcement', null, true);
    });
} catch (Throwable $e) { $caught = true; }
Database::execute('DROP TRIGGER test_fail_notification');
$after = (int)Database::fetchValue('SELECT COUNT(*) FROM announcements');
if (!$caught || $before !== $after) { fwrite(STDERR, "FAIL: notification failure did not roll back\n"); exit(1); }
echo "Notification insert failure rolls back transaction: OK\n";
