<?php
declare(strict_types=1);
// Uses an isolated in-memory database, never the installation database.
define('DB_DRIVER', 'sqlite');
define('SQLITE_DB_PATH', ':memory:');
require __DIR__ . '/../includes/bootstrap.php';
$ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM tasks ORDER BY id LIMIT 2'), 'id'));
if (count($ids) !== 2) throw new RuntimeException('Expected seeded tasks');
Database::execute("UPDATE tasks SET status='todo' WHERE id = ?", [$ids[0]]);
Database::execute("UPDATE tasks SET status='review' WHERE id = ?", [$ids[1]]);
$_SESSION['user_id'] = (int) Database::fetchValue("SELECT id FROM users WHERE role='admin' LIMIT 1");
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['action' => 'tasks_list', 'scope' => 'team', 'status' => 'in_progress'];
ob_start();
register_shutdown_function(function () use ($ids): void {
    $response = json_decode((string) ob_get_clean(), true);
    if (empty($response['ok'])) throw new RuntimeException('API failed');
    $tasks = array_column($response['tasks'], null, 'id');
    foreach ($ids as $id) {
        if (($tasks[$id]['status'] ?? '') !== 'in_progress' || ($tasks[$id]['status_label'] ?? '') !== 'در حال انجام') throw new RuntimeException('Legacy status not normalized');
    }
    if (Database::fetchValue('SELECT status FROM tasks WHERE id = ?', [$ids[0]]) !== 'todo') throw new RuntimeException('Legacy metadata must remain unchanged');
    echo "Legacy todo/review normalized in listing without changing stored history: OK\n";
});
require __DIR__ . '/../api.php';
