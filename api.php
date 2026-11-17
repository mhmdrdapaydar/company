<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = Auth::user();
if (!$user) {
    json_error('نشست شما منقضی شده است. لطفاً دوباره وارد شوید.', 401);
}
$uid = (int) $user['id'];
$isAdmin = Auth::isAdmin();
$isManager = Auth::isManager();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = strtolower(clean_line($_GET['action'] ?? ($_POST['action'] ?? ''), 60));
$data = request_data();

// CSRF برای همه درخواست‌های تغییر‌دهنده
if ($method !== 'GET') {
    $csrf = (string) ($data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!verify_csrf($csrf)) {
        json_error('توکن امنیتی نامعتبر است. صفحه را تازه‌سازی کنید.', 419);
    }
}

function require_manager(): void
{
    if (!Auth::isManager()) json_error('شما مجوز انجام این عملیات را ندارید.', 403);
}
function require_admin(): void
{
    if (!Auth::isAdmin()) json_error('این بخش فقط برای مدیر شرکت قابل دسترسی است.', 403);
}
function gen_task_code(): string
{
    for ($i = 0; $i < 20; $i++) {
        $code = 'TSK-' . random_int(1000, 9999) . random_int(10, 99);
        $exists = Database::fetchOne('SELECT id FROM tasks WHERE task_code = ? LIMIT 1', [$code]);
        if (!$exists) return $code;
    }
    return 'TSK-' . time();
}
function gen_employee_code(): string
{
    $max = (int) Database::fetchValue("SELECT COUNT(*) FROM users", [], 0);
    return 'EMP-' . (1001 + $max + random_int(0, 9));
}
function gen_project_code(): string
{
    $max = (int) Database::fetchValue("SELECT COUNT(*) FROM projects", [], 0);
    return 'PRJ-' . (101 + $max);
}
function parse_date_input(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    return Jalali::parseToGregorian((string) $v);
}
function parse_time_input(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    $t = en_digits(trim((string) $v));
    if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        $h = (int) $m[1]; $mi = (int) $m[2];
        if ($h >= 0 && $h <= 23 && $mi >= 0 && $mi <= 59) return sprintf('%02d:%02d', $h, $mi);
    }
    return null;
}
function enrich_task(array $t): array
{
    $t['id'] = (int) $t['id'];
    $t['project_id'] = $t['project_id'] !== null ? (int) $t['project_id'] : null;
    $t['department_id'] = $t['department_id'] !== null ? (int) $t['department_id'] : null;
    $t['assigned_to'] = (int) $t['assigned_to'];
    $t['created_by'] = (int) $t['created_by'];
    $t['progress'] = (int) $t['progress'];
    $t['estimated_minutes'] = (int) $t['estimated_minutes'];
    $t['is_archived'] = (int) $t['is_archived'];
    $t['status_label'] = task_status_label((string) $t['status']);
    $t['priority_label'] = task_priority_label((string) $t['priority']);
    $t['due_jalali'] = Jalali::toDateString($t['due_date'] ?? null, true);
    $t['due_human'] = Jalali::toHuman($t['due_date'] ?? null, true, true);
    $t['start_jalali'] = Jalali::toDateString($t['start_date'] ?? null, true);
    $t['start_human'] = Jalali::toHuman($t['start_date'] ?? null, true, true);
    $t['due_time_fa'] = !empty($t['due_time']) ? fa_digits((string) $t['due_time']) : '';
    $t['due_badge'] = Jalali::dueBadge($t['due_date'] ?? null, (string) $t['status']);
    $t['created_human'] = Jalali::toDateTimeHuman($t['created_at'] ?? null);
    $t['created_ago'] = Jalali::timeAgo($t['created_at'] ?? null);
    $t['estimated_fa'] = format_minutes_fa((int) $t['estimated_minutes']);
    $t['created_via_label'] = ($t['created_via'] ?? '') === 'voice_ai' ? '🎙️ تسک صوتی هوش مصنوعی' : '✍️ دستی';
    $t['voice_url'] = !empty($t['voice_file']) ? 'media.php?type=voice&file=' . rawurlencode((string) $t['voice_file']) : null;
    return $t;
}
function fetch_task(int $id): ?array
{
    return Database::fetchOne(
        'SELECT t.*, a.full_name AS assignee_name, a.avatar_color AS assignee_color, c.full_name AS creator_name, p.name AS project_name, p.color AS project_color, d.name AS department_name
         FROM tasks t
         LEFT JOIN users a ON a.id = t.assigned_to
         LEFT JOIN users c ON c.id = t.created_by
         LEFT JOIN projects p ON p.id = t.project_id
         LEFT JOIN departments d ON d.id = t.department_id
         WHERE t.id = ? LIMIT 1',
        [$id]
    );
}
function get_task_or_fail(int $id): array
{
    $t = fetch_task($id);
    if (!$t) json_error('تسک موردنظر یافت نشد.', 404);
    if (!Auth::canViewTask($t)) json_error('به این تسک دسترسی ندارید.', 403);
    return $t;
}
function create_task_record(array $input, string $createdVia, ?string $voiceFile, ?string $transcript, ?string $aiProvider, int $voiceDuration = 0): int
{
    global $uid;
    $now = now_datetime();
    $title = clean_line($input['title'] ?? '', 255);
    if ($title === '') json_error('عنوان تسک را وارد کنید.');
    $assignedTo = (int) ($input['assigned_to'] ?? $uid);
    if (!Auth::canAssignTo($assignedTo)) json_error('مجاز به واگذاری تسک به این کارمند نیستید.', 403);
    $target = Database::fetchOne('SELECT id, department_id FROM users WHERE id = ? AND is_active = 1', [$assignedTo]);
    if (!$target) json_error('کارمند مسئول تسک یافت نشد یا غیرفعال است.');

    $projectId = !empty($input['project_id']) ? (int) $input['project_id'] : null;
    if ($projectId !== null) {
        $p = Database::fetchOne('SELECT id FROM projects WHERE id = ?', [$projectId]);
        if (!$p) $projectId = null;
    }
    $deptId = !empty($input['department_id'])
        ? (int) $input['department_id']
        : ((int) ($target['department_id'] ?? 0) ?: null);
    $status = strtolower(clean_line($input['status'] ?? 'todo', 20));
    if (!in_array($status, ['todo', 'in_progress', 'review', 'done'], true)) $status = 'todo';
    $priority = strtolower(clean_line($input['priority'] ?? 'medium', 20));
    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) $priority = 'medium';
    $progress = max(0, min(100, (int) ($input['progress'] ?? ($status === 'done' ? 100 : 0))));
    if ($status === 'done') $progress = 100;
    $recurrence = strtolower(clean_line($input['recurrence'] ?? 'none', 20));
    if (!in_array($recurrence, ['none', 'daily', 'weekly', 'monthly'], true)) $recurrence = 'none';

    $startDate = parse_date_input($input['start_date'] ?? null);
    $dueDate = parse_date_input($input['due_date'] ?? null);
    $dueTime = parse_time_input($input['due_time'] ?? null);
    $completedAt = $status === 'done' ? $now : null;

    $taskId = Database::insert(
        'INSERT INTO tasks (task_code, title, description, status, priority, progress, project_id, department_id, assigned_to, created_by, start_date, due_date, due_time, estimated_minutes, recurrence, category, tags, created_via, voice_file, voice_transcript, voice_duration_sec, ai_provider, completed_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            gen_task_code(), $title, clean_text($input['description'] ?? '', 8000), $status, $priority, $progress,
            $projectId, $deptId, $assignedTo, $uid, $startDate, $dueDate, $dueTime,
            max(0, min(10080, (int) ($input['estimated_minutes'] ?? 0))), $recurrence,
            clean_line($input['category'] ?? '', 80), clean_line($input['tags'] ?? '', 500),
            $createdVia, $voiceFile, $transcript ? clean_text($transcript, 8000) : null,
            max(0, $voiceDuration), $aiProvider, $completedAt, $now, $now,
        ]
    );

    $subtasks = $input['subtasks'] ?? [];
    if (is_string($subtasks)) {
        $subtasks = array_filter(array_map('trim', preg_split('/\n+/', $subtasks) ?: []));
    }
    if (is_array($subtasks)) {
        $ord = 1;
        foreach (array_slice($subtasks, 0, 12) as $st) {
            $stTitle = clean_line(is_array($st) ? ($st['title'] ?? '') : $st, 255);
            if ($stTitle === '') continue;
            Database::insert(
                'INSERT INTO task_subtasks (task_id, title, is_done, sort_order, created_at) VALUES (?, ?, 0, ?, ?)',
                [$taskId, $stTitle, $ord++, $now]
            );
        }
    }

    log_activity($uid, 'create_task', 'task', $taskId, 'تسک «' . $title . '» ایجاد شد.');
    create_notification($assignedTo, $uid, 'task_assigned', 'تسک جدید برای شما ثبت شد', 'تسک «' . $title . '» به شما محول شد. سررسید: ' . Jalali::toHuman($dueDate, true, true), 'task', $taskId);
    return $taskId;
}
function task_detail_payload(array $t): array
{
    $out = enrich_task($t);
    $out['subtasks'] = Database::fetchAll('SELECT * FROM task_subtasks WHERE task_id = ? ORDER BY sort_order ASC, id ASC', [(int) $t['id']]);
    foreach ($out['subtasks'] as &$s) {
        $s['id'] = (int) $s['id']; $s['is_done'] = (int) $s['is_done'];
        $s['created_human'] = Jalali::toDateTimeHuman($s['created_at'] ?? null);
    }
    unset($s);
    $doneSubs = count(array_filter($out['subtasks'], fn($s) => (int) $s['is_done'] === 1));
    $out['subtasks_total'] = count($out['subtasks']);
    $out['subtasks_done'] = $doneSubs;
    $out['comments'] = Database::fetchAll(
        'SELECT c.*, u.full_name AS user_name, u.avatar_color FROM task_comments c JOIN users u ON u.id = c.user_id WHERE c.task_id = ? ORDER BY c.created_at ASC',
        [(int) $t['id']]
    );
    foreach ($out['comments'] as &$c) {
        $c['id'] = (int) $c['id'];
        $c['created_human'] = Jalali::toDateTimeHuman($c['created_at'] ?? null);
        $c['created_ago'] = Jalali::timeAgo($c['created_at'] ?? null);
        $c['voice_url'] = !empty($c['voice_file']) ? 'media.php?type=voice&file=' . rawurlencode((string) $c['voice_file']) : null;
    }
    unset($c);
    $out['attachments'] = Database::fetchAll(
        'SELECT a.*, u.full_name AS user_name FROM task_attachments a JOIN users u ON u.id = a.user_id WHERE a.task_id = ? ORDER BY a.created_at DESC',
        [(int) $t['id']]
    );
    foreach ($out['attachments'] as &$a) {
        $a['id'] = (int) $a['id'];
        $a['size_fa'] = format_bytes_fa((int) $a['file_size']);
        $a['created_human'] = Jalali::toDateTimeHuman($a['created_at'] ?? null);
        $a['download_url'] = 'media.php?type=attachment&file=' . rawurlencode((string) $a['stored_name']) . '&download=1';
        $a['view_url'] = 'media.php?type=attachment&file=' . rawurlencode((string) $a['stored_name']);
    }
    unset($a);
    $out['time_logs'] = Database::fetchAll(
        'SELECT l.*, u.full_name AS user_name FROM task_time_logs l JOIN users u ON u.id = l.user_id WHERE l.task_id = ? ORDER BY l.started_at DESC',
        [(int) $t['id']]
    );
    $totalMin = 0;
    foreach ($out['time_logs'] as &$l) {
        $l['id'] = (int) $l['id'];
        $totalMin += (int) $l['duration_minutes'];
        $l['started_human'] = Jalali::toDateTimeHuman($l['started_at'] ?? null);
        $l['ended_human'] = $l['ended_at'] ? Jalali::toDateTimeHuman($l['ended_at']) : 'در حال انجام ⏱️';
        $l['duration_fa'] = format_minutes_fa((int) $l['duration_minutes']);
    }
    unset($l);
    $out['total_logged_minutes'] = $totalMin;
    $out['total_logged_fa'] = format_minutes_fa($totalMin);
    $running = Database::fetchOne('SELECT * FROM task_time_logs WHERE task_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1', [(int) $t['id']]);
    $out['running_timer'] = $running ? ['id' => (int) $running['id'], 'started_at' => $running['started_at'], 'started_human' => Jalali::toDateTimeHuman($running['started_at'])] : null;
    return $out;
}

// ============ روتینگ ============
try {
    switch ($action) {

        case 'me':
        case 'bootstrap': {
            $fresh = Auth::refresh();
            $notifCount = (int) Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$uid], 0);
            json_success([
                'user' => $fresh,
                'csrf_token' => csrf_token(),
                'today' => [
                    'gregorian' => today_date(),
                    'jalali' => Jalali::toDateString(today_date(), true),
                    'jalali_en' => Jalali::toDateString(today_date(), false),
                    'human' => Jalali::toHuman(today_date(), true, true),
                    'weekday' => Jalali::weekdayName(today_date()),
                    'time' => fa_digits(date('H:i')),
                ],
                'settings' => [
                    'company_name' => get_setting('company_name', APP_NAME),
                    'company_slogan' => get_setting('company_slogan', ''),
                    'work_start_time' => get_setting('work_start_time', '08:30'),
                    'work_end_time' => get_setting('work_end_time', '17:00'),
                    'ai_provider' => get_setting('ai_provider', AI_PROVIDER),
                    'ai_auto_create' => get_setting('ai_auto_create', '0'),
                    'allow_employee_self_task' => get_setting('allow_employee_self_task', '1'),
                    'allow_employee_peer_task' => get_setting('allow_employee_peer_task', '0'),
                ],
                'notifications_unread' => $notifCount,
                'is_admin' => $isAdmin,
                'is_manager' => $isManager,
            ]);
            break;
        }

        case 'dashboard_stats': {
            $today = today_date();
            $weekAgo = date('Y-m-d', strtotime('-7 days'));
            if ($isManager) {
                $base = '1=1'; $params = [];
            } else {
                $base = '(assigned_to = ? OR created_by = ?)'; $params = [$uid, $uid];
            }
            $counts = Database::fetchAll(
                "SELECT status, COUNT(*) AS c FROM tasks WHERE {$base} AND is_archived = 0 GROUP BY status",
                $params
            );
            $byStatus = ['todo' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0];
            foreach ($counts as $r) $byStatus[(string) $r['status']] = (int) $r['c'];
            $total = array_sum($byStatus);
            $overdue = (int) Database::fetchValue(
                "SELECT COUNT(*) FROM tasks WHERE {$base} AND is_archived = 0 AND status != 'done' AND due_date IS NOT NULL AND due_date < ?",
                array_merge($params, [$today]), 0
            );
            $dueToday = (int) Database::fetchValue(
                "SELECT COUNT(*) FROM tasks WHERE {$base} AND is_archived = 0 AND status != 'done' AND due_date = ?",
                array_merge($params, [$today]), 0
            );
            $doneWeek = (int) Database::fetchValue(
                "SELECT COUNT(*) FROM tasks WHERE {$base} AND is_archived = 0 AND status = 'done' AND DATE(completed_at) >= ?",
                array_merge($params, [$weekAgo]), 0
            );
            $myTasks = Database::fetchAll(
                "SELECT t.*, a.full_name AS assignee_name, p.name AS project_name, p.color AS project_color
                 FROM tasks t LEFT JOIN users a ON a.id = t.assigned_to LEFT JOIN projects p ON p.id = t.project_id
                 WHERE {$base} AND t.is_archived = 0
                 ORDER BY CASE WHEN t.status='done' THEN 1 ELSE 0 END, t.due_date ASC LIMIT 8",
                $params
            );
            $myTasks = array_map('enrich_task', $myTasks);
            // نمودار ۷ روز اخیر: تسک‌های ایجادشده و تکمیل‌شده به تفکیک روز
            $chart = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $created = (int) Database::fetchValue(
                    "SELECT COUNT(*) FROM tasks WHERE {$base} AND DATE(created_at) = ?",
                    array_merge($params, [$d]), 0
                );
                $completed = (int) Database::fetchValue(
                    "SELECT COUNT(*) FROM tasks WHERE {$base} AND status='done' AND DATE(completed_at) = ?",
                    array_merge($params, [$d]), 0
                );
                $chart[] = ['date' => $d, 'label' => Jalali::toHuman($d, false, false), 'created' => $created, 'completed' => $completed];
            }
            // توزیع اولویت
            $prioRows = Database::fetchAll(
                "SELECT priority, COUNT(*) AS c FROM tasks WHERE {$base} AND is_archived = 0 AND status != 'done' GROUP BY priority",
                $params
            );
            $byPriority = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            foreach ($prioRows as $r) $byPriority[(string) $r['priority']] = (int) $r['c'];
            // حضور امروز
            $attToday = Database::fetchOne('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1', [$uid, $today]);
            $teamToday = null;
            if ($isManager) {
                $teamToday = (int) Database::fetchValue('SELECT COUNT(DISTINCT user_id) FROM attendance WHERE work_date = ?', [$today], 0);
            }
            $pendingLeaves = $isManager ? (int) Database::fetchValue("SELECT COUNT(*) FROM leave_requests WHERE status='pending'", [], 0) : 0;
            $teamCount = $isManager ? (int) Database::fetchValue('SELECT COUNT(*) FROM users WHERE is_active = 1', [], 0) : 0;
            $projectCount = $isManager ? (int) Database::fetchValue("SELECT COUNT(*) FROM projects WHERE status='active'", [], 0) : 0;
            $completionRate = $total > 0 ? (int) round(($byStatus['done'] / $total) * 100) : 0;
            json_success([
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
                'total' => $total,
                'overdue' => $overdue,
                'due_today' => $dueToday,
                'done_week' => $doneWeek,
                'completion_rate' => $completionRate,
                'my_tasks' => $myTasks,
                'chart_7d' => $chart,
                'attendance_today' => $attToday ? [
                    'check_in' => $attToday['check_in'], 'check_out' => $attToday['check_out'],
                    'check_in_fa' => fa_digits(substr((string) $attToday['check_in'], 11, 5)),
                    'check_out_fa' => $attToday['check_out'] ? fa_digits(substr((string) $attToday['check_out'], 11, 5)) : null,
                    'total_minutes' => (int) $attToday['total_minutes'],
                    'total_fa' => format_minutes_fa((int) $attToday['total_minutes']),
                    'work_mode' => $attToday['work_mode'],
                ] : null,
                'team_present_today' => $teamToday,
                'pending_leaves' => $pendingLeaves,
                'team_count' => $teamCount,
                'project_count' => $projectCount,
            ]);
            break;
        }

        case 'tasks_list': {
            $status = strtolower(clean_line($data['status'] ?? '', 20));
            $search = clean_line($data['search'] ?? '', 100);
            $projectId = (int) ($data['project_id'] ?? 0);
            $assignedTo = (int) ($data['assigned_to'] ?? 0);
            $priority = strtolower(clean_line($data['priority'] ?? '', 20));
            $scope = strtolower(clean_line($data['scope'] ?? 'mine', 20));
            $archived = (($_GET['archived'] ?? '0') === '1' || ($data['archived'] ?? '') === '1');
            $dueFrom = parse_date_input($data['due_from'] ?? null);
            $dueTo = parse_date_input($data['due_to'] ?? null);

            $where = ['t.is_archived = ?'];
            $params = [$archived ? 1 : 0];
            if ($scope === 'mine' || !$isManager) {
                $where[] = '(t.assigned_to = ? OR t.created_by = ?)';
                $params[] = $uid; $params[] = $uid;
            } elseif ($scope === 'team' && $isManager) {
                // همه تسک‌ها
            } elseif ($scope === 'assigned' && $isManager) {
                $where[] = 't.assigned_to = ?'; $params[] = $uid;
            }
            if ($status !== '' && in_array($status, ['todo', 'in_progress', 'review', 'done'], true)) {
                $where[] = 't.status = ?'; $params[] = $status;
            }
            if ($priority !== '' && in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
                $where[] = 't.priority = ?'; $params[] = $priority;
            }
            if ($projectId > 0) { $where[] = 't.project_id = ?'; $params[] = $projectId; }
            if ($assignedTo > 0 && $isManager) { $where[] = 't.assigned_to = ?'; $params[] = $assignedTo; }
            if ($dueFrom) { $where[] = 't.due_date >= ?'; $params[] = $dueFrom; }
            if ($dueTo) { $where[] = 't.due_date <= ?'; $params[] = $dueTo; }
            if ($search !== '') {
                $where[] = '(t.title LIKE ? OR t.description LIKE ? OR t.task_code LIKE ? OR t.tags LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
            }
            $sql = 'SELECT t.*, a.full_name AS assignee_name, a.avatar_color AS assignee_color, c.full_name AS creator_name, p.name AS project_name, p.color AS project_color
                    FROM tasks t
                    LEFT JOIN users a ON a.id = t.assigned_to
                    LEFT JOIN users c ON c.id = t.created_by
                    LEFT JOIN projects p ON p.id = t.project_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY CASE t.status WHEN \'todo\' THEN 0 WHEN \'in_progress\' THEN 1 WHEN \'review\' THEN 2 ELSE 3 END, t.due_date ASC, t.id DESC LIMIT 500';
            $rows = Database::fetchAll($sql, $params);
            // شمارش زیرتسک‌ها
            $rows = array_map(function ($t) {
                $t = enrich_task($t);
                $subs = Database::fetchAll('SELECT is_done FROM task_subtasks WHERE task_id = ?', [(int) $t['id']]);
                $t['subtasks_total'] = count($subs);
                $t['subtasks_done'] = count(array_filter($subs, fn($s) => (int) $s['is_done'] === 1));
                return $t;
            }, $rows);
            json_success(['tasks' => $rows, 'count' => count($rows)]);
            break;
        }

        case 'task_get': {
            $t = get_task_or_fail((int) ($data['id'] ?? 0));
            json_success(['task' => task_detail_payload($t)]);
            break;
        }

        case 'task_create': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $taskId = create_task_record($data, 'manual', null, null, null);
            $t = fetch_task($taskId);
            json_success(['task' => task_detail_payload($t)], 'تسک با موفقیت ایجاد شد.');
            break;
        }

        case 'task_update': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $t = get_task_or_fail((int) ($data['id'] ?? 0));
            $now = now_datetime();
            $sets = []; $params = [];
            if (isset($data['title'])) { $sets[] = 'title = ?'; $params[] = clean_line($data['title'], 255); }
            if (isset($data['description'])) { $sets[] = 'description = ?'; $params[] = clean_text($data['description'], 8000); }
            if (isset($data['status'])) {
                $st = strtolower(clean_line($data['status'], 20));
                if (in_array($st, ['todo', 'in_progress', 'review', 'done'], true)) {
                    $sets[] = 'status = ?'; $params[] = $st;
                    if ($st === 'done') { $sets[] = 'completed_at = ?'; $params[] = $now; $sets[] = 'progress = 100'; }
                    else { $sets[] = 'completed_at = NULL'; }
                }
            }
            if (isset($data['priority'])) {
                $pr = strtolower(clean_line($data['priority'], 20));
                if (in_array($pr, ['low', 'medium', 'high', 'urgent'], true)) { $sets[] = 'priority = ?'; $params[] = $pr; }
            }
            if (isset($data['progress'])) { $sets[] = 'progress = ?'; $params[] = max(0, min(100, (int) $data['progress'])); }
            if (array_key_exists('project_id', $data)) {
                $pid = (int) $data['project_id'];
                $sets[] = 'project_id = ?'; $params[] = $pid > 0 ? $pid : null;
            }
            if (array_key_exists('assigned_to', $data)) {
                $at = (int) $data['assigned_to'];
                if ($at > 0 && Auth::canAssignTo($at)) { $sets[] = 'assigned_to = ?'; $params[] = $at; }
            }
            if (array_key_exists('start_date', $data)) { $sets[] = 'start_date = ?'; $params[] = parse_date_input($data['start_date']); }
            if (array_key_exists('due_date', $data)) { $sets[] = 'due_date = ?'; $params[] = parse_date_input($data['due_date']); }
            if (array_key_exists('due_time', $data)) { $sets[] = 'due_time = ?'; $params[] = parse_time_input($data['due_time']); }
            if (isset($data['estimated_minutes'])) { $sets[] = 'estimated_minutes = ?'; $params[] = max(0, min(10080, (int) $data['estimated_minutes'])); }
            if (isset($data['category'])) { $sets[] = 'category = ?'; $params[] = clean_line($data['category'], 80); }
            if (isset($data['tags'])) { $sets[] = 'tags = ?'; $params[] = clean_line($data['tags'], 500); }
            if (isset($data['recurrence'])) {
                $rc = strtolower(clean_line($data['recurrence'], 20));
                if (in_array($rc, ['none', 'daily', 'weekly', 'monthly'], true)) { $sets[] = 'recurrence = ?'; $params[] = $rc; }
            }
            if (isset($data['review_note'])) { $sets[] = 'review_note = ?'; $params[] = clean_text($data['review_note'], 3000); }
            if (isset($data['is_archived'])) { $sets[] = 'is_archived = ?'; $params[] = ((int) $data['is_archived'] === 1) ? 1 : 0; }
            if (empty($sets)) json_error('موردی برای ویرایش ارسال نشده است.');
            $sets[] = 'updated_at = ?'; $params[] = $now; $params[] = (int) $t['id'];
            Database::execute('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            log_activity($uid, 'update_task', 'task', (int) $t['id'], 'تسک «' . $t['title'] . '» ویرایش شد.');
            if (isset($data['status']) && strtolower(clean_line($data['status'], 20)) === 'review') {
                create_notification((int) $t['created_by'], $uid, 'task_review', 'تسک آماده بررسی است', 'تسک «' . $t['title'] . '» برای بررسی نهایی ارسال شد.', 'task', (int) $t['id']);
            }
            $fresh = fetch_task((int) $t['id']);
            json_success(['task' => task_detail_payload($fresh)], 'تسک به‌روزرسانی شد.');
            break;
        }

        case 'task_delete': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $t = get_task_or_fail((int) ($data['id'] ?? 0));
            if (!($isAdmin || (int) $t['created_by'] === $uid)) json_error('فقط سازنده تسک یا مدیر شرکت می‌تواند آن را حذف کند.', 403);
            Database::execute('DELETE FROM tasks WHERE id = ?', [(int) $t['id']]);
            log_activity($uid, 'delete_task', 'task', (int) $t['id'], 'تسک «' . $t['title'] . '» حذف شد.');
            json_success([], 'تسک حذف شد.');
            break;
        }

        case 'subtask_add': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $t = get_task_or_fail((int) ($data['task_id'] ?? 0));
            $title = clean_line($data['title'] ?? '', 255);
            if ($title === '') json_error('عنوان زیرتسک را وارد کنید.');
            $maxOrd = (int) Database::fetchValue('SELECT COALESCE(MAX(sort_order),0) FROM task_subtasks WHERE task_id = ?', [(int) $t['id']], 0);
            $id = Database::insert(
                'INSERT INTO task_subtasks (task_id, title, is_done, sort_order, created_at) VALUES (?, ?, 0, ?, ?)',
                [(int) $t['id'], $title, $maxOrd + 1, now_datetime()]
            );
            json_success(['id' => $id], 'زیرتسک اضافه شد.');
            break;
        }

        case 'subtask_toggle': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $sub = Database::fetchOne('SELECT * FROM task_subtasks WHERE id = ?', [(int) ($data['id'] ?? 0)]);
            if (!$sub) json_error('زیرتسک یافت نشد.', 404);
            $t = get_task_or_fail((int) $sub['task_id']);
            $newDone = ((int) $sub['is_done'] === 1) ? 0 : 1;
            Database::execute(
                'UPDATE task_subtasks SET is_done = ?, completed_by = ?, completed_at = ? WHERE id = ?',
                [$newDone, $newDone ? $uid : null, $newDone ? now_datetime() : null, (int) $sub['id']]
            );
            // به‌روزرسانی خودکار پیشرفت بر اساس چک‌لیست
            $subs = Database::fetchAll('SELECT is_done FROM task_subtasks WHERE task_id = ?', [(int) $t['id']]);
            if (!empty($subs)) {
                $done = count(array_filter($subs, fn($s) => (int) $s['is_done'] === 1));
                $pct = (int) round(($done / count($subs)) * 100);
                Database::execute('UPDATE tasks SET progress = ?, updated_at = ? WHERE id = ?', [$pct, now_datetime(), (int) $t['id']]);
            }
            json_success(['is_done' => $newDone], $newDone ? 'زیرتسک انجام شد ✅' : 'زیرتسک به حالت انجام‌نشده برگشت.');
            break;
        }

        case 'subtask_delete': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $sub = Database::fetchOne('SELECT * FROM task_subtasks WHERE id = ?', [(int) ($data['id'] ?? 0)]);
            if (!$sub) json_error('زیرتسک یافت نشد.', 404);
            get_task_or_fail((int) $sub['task_id']);
            Database::execute('DELETE FROM task_subtasks WHERE id = ?', [(int) $sub['id']]);
            json_success([], 'زیرتسک حذف شد.');
            break;
        }

        case 'comment_add': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $t = get_task_or_fail((int) ($data['task_id'] ?? 0));
            $body = clean_text($data['body'] ?? '', 5000);
            $voiceFile = null; $voiceDur = 0;
            if (!empty($_FILES['voice']) && ($_FILES['voice']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $saved = save_uploaded_voice($_FILES['voice']);
                $voiceFile = $saved['stored_name'];
                $voiceDur = (int) ($data['voice_duration_sec'] ?? 0);
            }
            if ($body === '' && $voiceFile === null) json_error('متن نظر یا فایل صوتی را وارد کنید.');
            if ($body === '') $body = '🎙️ پیام صوتی';
            $cid = Database::insert(
                'INSERT INTO task_comments (task_id, user_id, body, voice_file, voice_duration_sec, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [(int) $t['id'], $uid, $body, $voiceFile, $voiceDur, now_datetime()]
            );
            log_activity($uid, 'comment', 'task', (int) $t['id'], 'روی تسک «' . $t['title'] . '» نظر ثبت شد.');
            $other = (int) $t['assigned_to'] === $uid ? (int) $t['created_by'] : (int) $t['assigned_to'];
            create_notification($other, $uid, 'task_comment', 'نظر جدید روی تسک', $user['full_name'] . ' روی تسک «' . $t['title'] . '» نظر داد.', 'task', (int) $t['id']);
            json_success(['id' => $cid], 'نظر ثبت شد.');
            break;
        }

        case 'attachment_upload': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $t = get_task_or_fail((int) ($data['task_id'] ?? 0));
            if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                json_error('فایل پیوست دریافت نشد.');
            }
            $saved = save_uploaded_attachment($_FILES['file']);
            $aid = Database::insert(
                'INSERT INTO task_attachments (task_id, user_id, original_name, stored_name, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [(int) $t['id'], $uid, $saved['original_name'], $saved['stored_name'], $saved['mime_type'], $saved['file_size'], now_datetime()]
            );
            log_activity($uid, 'attach', 'task', (int) $t['id'], 'فایل «' . $saved['original_name'] . '» به تسک پیوست شد.');
            json_success(['id' => $aid], 'فایل پیوست شد.');
            break;
        }

        case 'attachment_delete': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $att = Database::fetchOne('SELECT * FROM task_attachments WHERE id = ?', [(int) ($data['id'] ?? 0)]);
            if (!$att) json_error('پیوست یافت نشد.', 404);
            get_task_or_fail((int) $att['task_id']);
            Database::execute('DELETE FROM task_attachments WHERE id = ?', [(int) $att['id']]);
            $path = ATTACHMENTS_PATH . '/' . basename((string) $att['stored_name']);
            if (is_file($path)) @unlink($path);
            json_success([], 'پیوست حذف شد.');
            break;
        }

        case 'timer_start': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $t = get_task_or_fail((int) ($data['task_id'] ?? 0));
            $running = Database::fetchOne('SELECT id FROM task_time_logs WHERE task_id = ? AND ended_at IS NULL LIMIT 1', [(int) $t['id']]);
            if ($running) json_error('تایمر این تسک در حال اجراست.');
            $id = Database::insert(
                'INSERT INTO task_time_logs (task_id, user_id, started_at, ended_at, duration_minutes, note, created_at) VALUES (?, ?, ?, NULL, 0, ?, ?)',
                [(int) $t['id'], $uid, now_datetime(), clean_line($data['note'] ?? '', 300), now_datetime()]
            );
            if ((string) $t['status'] === 'todo') {
                Database::execute("UPDATE tasks SET status='in_progress', updated_at=? WHERE id=?", [now_datetime(), (int) $t['id']]);
            }
            json_success(['id' => $id], '⏱️ تایمر شروع شد.');
            break;
        }

        case 'timer_stop': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $t = get_task_or_fail((int) ($data['task_id'] ?? 0));
            $running = Database::fetchOne('SELECT * FROM task_time_logs WHERE task_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1', [(int) $t['id']]);
            if (!$running) json_error('تایمر فعالی برای این تسک وجود ندارد.');
            $startTs = strtotime((string) $running['started_at']);
            $mins = max(1, (int) round((time() - ($startTs ?: time())) / 60));
            Database::execute(
                'UPDATE task_time_logs SET ended_at = ?, duration_minutes = ? WHERE id = ?',
                [now_datetime(), $mins, (int) $running['id']]
            );
            json_success(['minutes' => $mins, 'minutes_fa' => format_minutes_fa($mins)], '⏱️ تایمر متوقف شد. مدت ثبت‌شده: ' . format_minutes_fa($mins));
            break;
        }

        case 'voice_preview': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $transcript = clean_text($data['transcript'] ?? '', 8000);
            $savedVoice = null;
            $voiceFileName = null;
            $voiceDuration = (int) ($data['voice_duration_sec'] ?? 0);
            if (!empty($_FILES['audio']) && ($_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $savedVoice = save_uploaded_voice($_FILES['audio']);
                $voiceFileName = $savedVoice['stored_name'];
            }
            if ($transcript === '' && $savedVoice === null) {
                json_error('لطفاً صدا ضبط کنید، فایل صوتی آپلود کنید یا متن فرمان را بنویسید.');
            }
            $assignable = Auth::assignableUsers();
            $projects = Database::fetchAll("SELECT id, name, code FROM projects WHERE status='active' ORDER BY id ASC");
            $result = AiService::processVoiceCommand($savedVoice, $transcript, $assignable, $projects, $uid);
            if (empty($result['tasks'])) json_error('هوش مصنوعی نتوانست تسکی از این پیام استخراج کند. لطفاً واضح‌تر صحبت کنید.');
            log_activity($uid, 'voice_preview', 'task', null, 'پردازش فرمان صوتی انجام شد (' . count($result['tasks']) . ' تسک).');
            json_success([
                'transcript' => $result['transcript'],
                'provider' => $result['provider'],
                'tasks' => $result['tasks'],
                'notes' => $result['notes'] ?? [],
                'voice_file' => $voiceFileName,
                'voice_duration_sec' => $voiceDuration,
                'voice_url' => $voiceFileName ? 'media.php?type=voice&file=' . rawurlencode($voiceFileName) : null,
            ], 'پردازش صوتی انجام شد. پیش‌نمایش تسک‌ها را بررسی و تأیید کنید.');
            break;
        }

        case 'voice_create': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $tasksJson = (string) ($data['tasks'] ?? '');
            $decoded = json_decode($tasksJson, true);
            if (!is_array($decoded) || empty($decoded)) {
                // پشتیبانی ارسال مستقیم آرایه
                $decoded = $data['tasks'] ?? null;
            }
            if (!is_array($decoded) || empty($decoded)) json_error('لیست تسک‌های تأییدشده ارسال نشده است.');
            $voiceFile = clean_line($data['voice_file'] ?? '', 120) ?: null;
            if ($voiceFile !== null) {
                $voiceFile = basename($voiceFile);
                if (!is_file(VOICES_PATH . '/' . $voiceFile)) $voiceFile = null;
            }
            $transcript = clean_text($data['transcript'] ?? '', 8000) ?: null;
            $provider = clean_line($data['provider'] ?? 'دستیار هوشمند فارسی', 80);
            $duration = (int) ($data['voice_duration_sec'] ?? 0);
            $createdIds = [];
            foreach ($decoded as $item) {
                if (!is_array($item)) continue;
                $tid = create_task_record($item, 'voice_ai', $voiceFile, $transcript, $provider, $duration);
                $createdIds[] = $tid;
            }
            if (empty($createdIds)) json_error('هیچ تسک معتبری ایجاد نشد.');
            $createdTasks = [];
            foreach ($createdIds as $tid) {
                $tt = fetch_task($tid);
                if ($tt) $createdTasks[] = enrich_task($tt);
            }
            json_success(['tasks' => $createdTasks, 'ids' => $createdIds], '🎉 ' . fa_digits(count($createdIds)) . ' تسک صوتی با موفقیت در تقویم شمسی ثبت شد.');
            break;
        }

        case 'voice_suggest_subtasks': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $title = clean_line($data['title'] ?? '', 255);
            $category = clean_line($data['category'] ?? '', 80);
            if ($title === '') json_error('عنوان تسک را وارد کنید.');
            $subs = PersianVoiceParser::suggestSubtasks($title, $category);
            json_success(['subtasks' => $subs]);
            break;
        }

        case 'users_list': {
            require_manager();
            $rows = Database::fetchAll(
                'SELECT u.id, u.employee_code, u.username, u.full_name, u.email, u.phone, u.role, u.position_title, u.department_id, u.avatar_color, u.hire_date, u.bio, u.is_active, u.last_login_at, u.created_at, d.name AS department_name
                 FROM users u LEFT JOIN departments d ON d.id = u.department_id ORDER BY u.id ASC'
            );
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['department_id'] = $r['department_id'] !== null ? (int) $r['department_id'] : null;
                $r['is_active'] = (int) $r['is_active'];
                $r['role_label'] = user_role_label((string) $r['role']);
                $r['hire_jalali'] = Jalali::toDateString($r['hire_date'] ?? null, true);
                $r['last_login_human'] = $r['last_login_at'] ? Jalali::toDateTimeHuman($r['last_login_at']) : '—';
                $r['initials'] = persian_initials((string) $r['full_name']);
                $r['tasks_count'] = (int) Database::fetchValue('SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND is_archived=0', [(int) $r['id']], 0);
                $r['tasks_open'] = (int) Database::fetchValue("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND is_archived=0 AND status != 'done'", [(int) $r['id']], 0);
            }
            unset($r);
            json_success(['users' => $rows]);
            break;
        }

        case 'user_create': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $fullName = clean_line($data['full_name'] ?? '', 191);
            $username = clean_line($data['username'] ?? '', 100);
            $password = (string) ($data['password'] ?? '');
            $email = clean_line($data['email'] ?? '', 191);
            $role = strtolower(clean_line($data['role'] ?? 'employee', 20));
            if (!in_array($role, ['admin', 'manager', 'employee'], true)) $role = 'employee';
            if ($role === 'admin') require_admin();
            if ($fullName === '' || $username === '' || $password === '') json_error('نام و نام خانوادگی، نام کاربری و رمز عبور الزامی است.');
            if (mb_strlen($password, 'UTF-8') < 6) json_error('رمز عبور باید حداقل ۶ کاراکتر باشد.');
            if (Database::fetchOne('SELECT id FROM users WHERE username = ? LIMIT 1', [$username])) json_error('این نام کاربری قبلاً استفاده شده است.');
            if ($email !== '' && Database::fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) json_error('این ایمیل قبلاً ثبت شده است.');
            $empCode = clean_line($data['employee_code'] ?? '', 50) ?: gen_employee_code();
            if (Database::fetchOne('SELECT id FROM users WHERE employee_code = ? LIMIT 1', [$empCode])) json_error('این کد پرسنلی قبلاً ثبت شده است.');
            $deptId = !empty($data['department_id']) ? (int) $data['department_id'] : null;
            $colors = ['#0f766e', '#0284c7', '#7c3aed', '#d97706', '#e11d48', '#4f46e5', '#059669', '#db2777'];
            $newId = Database::insert(
                'INSERT INTO users (employee_code, username, password_hash, full_name, email, phone, role, position_title, department_id, avatar_color, hire_date, bio, is_active, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [
                    $empCode, $username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email,
                    clean_line($data['phone'] ?? '', 30), $role, clean_line($data['position_title'] ?? '', 150),
                    $deptId, $colors[random_int(0, count($colors) - 1)],
                    parse_date_input($data['hire_date'] ?? null) ?: today_date(),
                    clean_text($data['bio'] ?? '', 2000), $uid, now_datetime(), now_datetime(),
                ]
            );
            log_activity($uid, 'create_user', 'user', $newId, 'کارمند جدید «' . $fullName . '» (' . $empCode . ') معرفی شد.');
            json_success(['id' => $newId], 'کارمند «' . $fullName . '» با موفقیت معرفی شد.');
            break;
        }

        case 'user_update': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $targetId = (int) ($data['id'] ?? 0);
            $target = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$targetId]);
            if (!$target) json_error('کارمند یافت نشد.', 404);
            if ((string) $target['role'] === 'admin' && !$isAdmin) json_error('فقط مدیر شرکت می‌تواند حساب مدیر را ویرایش کند.', 403);
            $sets = []; $params = [];
            if (isset($data['full_name'])) { $sets[] = 'full_name = ?'; $params[] = clean_line($data['full_name'], 191); }
            if (isset($data['email'])) { $sets[] = 'email = ?'; $params[] = clean_line($data['email'], 191); }
            if (isset($data['phone'])) { $sets[] = 'phone = ?'; $params[] = clean_line($data['phone'], 30); }
            if (isset($data['role'])) {
                $rl = strtolower(clean_line($data['role'], 20));
                if (in_array($rl, ['admin', 'manager', 'employee'], true)) {
                    if ($rl === 'admin') require_admin();
                    $sets[] = 'role = ?'; $params[] = $rl;
                }
            }
            if (isset($data['position_title'])) { $sets[] = 'position_title = ?'; $params[] = clean_line($data['position_title'], 150); }
            if (array_key_exists('department_id', $data)) { $sets[] = 'department_id = ?'; $params[] = !empty($data['department_id']) ? (int) $data['department_id'] : null; }
            if (array_key_exists('hire_date', $data)) { $sets[] = 'hire_date = ?'; $params[] = parse_date_input($data['hire_date']); }
            if (isset($data['bio'])) { $sets[] = 'bio = ?'; $params[] = clean_text($data['bio'], 2000); }
            if (isset($data['is_active'])) {
                if ($targetId === $uid) json_error('نمی‌توانید حساب خودتان را غیرفعال کنید.');
                $sets[] = 'is_active = ?'; $params[] = ((int) $data['is_active'] === 1) ? 1 : 0;
            }
            if (!empty($data['password'])) {
                $pw = (string) $data['password'];
                if (mb_strlen($pw, 'UTF-8') < 6) json_error('رمز عبور جدید باید حداقل ۶ کاراکتر باشد.');
                $sets[] = 'password_hash = ?'; $params[] = password_hash($pw, PASSWORD_DEFAULT);
            }
            if (empty($sets)) json_error('موردی برای ویرایش ارسال نشده است.');
            $sets[] = 'updated_at = ?'; $params[] = now_datetime(); $params[] = $targetId;
            Database::execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            log_activity($uid, 'update_user', 'user', $targetId, 'اطلاعات کارمند «' . $target['full_name'] . '» ویرایش شد.');
            json_success([], 'اطلاعات کارمند به‌روزرسانی شد.');
            break;
        }

        case 'user_delete': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_admin();
            $targetId = (int) ($data['id'] ?? 0);
            if ($targetId === $uid) json_error('نمی‌توانید حساب خودتان را حذف کنید.');
            $target = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$targetId]);
            if (!$target) json_error('کارمند یافت نشد.', 404);
            // انتقال تسک‌های کارمند حذف‌شونده به مدیر
            Database::execute('UPDATE tasks SET assigned_to = ? WHERE assigned_to = ?', [$uid, $targetId]);
            Database::execute('DELETE FROM users WHERE id = ?', [$targetId]);
            log_activity($uid, 'delete_user', 'user', $targetId, 'کارمند «' . $target['full_name'] . '» از سیستم حذف شد.');
            json_success([], 'کارمند حذف شد. تسک‌های وی به شما منتقل شد.');
            break;
        }

        case 'departments_list': {
            $rows = Database::fetchAll('SELECT * FROM departments ORDER BY id ASC');
            foreach ($rows as &$d) {
                $d['id'] = (int) $d['id'];
                $d['manager_id'] = $d['manager_id'] !== null ? (int) $d['manager_id'] : null;
                $d['members_count'] = (int) Database::fetchValue('SELECT COUNT(*) FROM users WHERE department_id = ? AND is_active=1', [(int) $d['id']], 0);
                $d['projects_count'] = (int) Database::fetchValue('SELECT COUNT(*) FROM projects WHERE department_id = ? AND status=\'active\'', [(int) $d['id']], 0);
                $d['manager_name'] = $d['manager_id'] ? (Database::fetchValue('SELECT full_name FROM users WHERE id = ?', [$d['manager_id']], '') ?: '') : '';
            }
            unset($d);
            json_success(['departments' => $rows]);
            break;
        }

        case 'department_create': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $name = clean_line($data['name'] ?? '', 150);
            if ($name === '') json_error('نام واحد سازمانی را وارد کنید.');
            if (Database::fetchOne('SELECT id FROM departments WHERE name = ? LIMIT 1', [$name])) json_error('واحدی با این نام قبلاً ثبت شده است.');
            $id = Database::insert(
                'INSERT INTO departments (name, description, color, manager_id, created_at) VALUES (?, ?, ?, ?, ?)',
                [$name, clean_line($data['description'] ?? '', 500), clean_line($data['color'] ?? '#0f766e', 20), !empty($data['manager_id']) ? (int) $data['manager_id'] : null, now_datetime()]
            );
            log_activity($uid, 'create_department', 'department', $id, 'واحد سازمانی «' . $name . '» ایجاد شد.');
            json_success(['id' => $id], 'واحد سازمانی ایجاد شد.');
            break;
        }

        case 'department_update': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $id = (int) ($data['id'] ?? 0);
            if (!Database::fetchOne('SELECT id FROM departments WHERE id = ?', [$id])) json_error('واحد یافت نشد.', 404);
            $sets = []; $params = [];
            if (isset($data['name'])) { $sets[] = 'name = ?'; $params[] = clean_line($data['name'], 150); }
            if (isset($data['description'])) { $sets[] = 'description = ?'; $params[] = clean_line($data['description'], 500); }
            if (isset($data['color'])) { $sets[] = 'color = ?'; $params[] = clean_line($data['color'], 20); }
            if (array_key_exists('manager_id', $data)) { $sets[] = 'manager_id = ?'; $params[] = !empty($data['manager_id']) ? (int) $data['manager_id'] : null; }
            if (empty($sets)) json_error('موردی برای ویرایش ارسال نشده است.');
            $params[] = $id;
            Database::execute('UPDATE departments SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            json_success([], 'واحد سازمانی به‌روزرسانی شد.');
            break;
        }

        case 'department_delete': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $id = (int) ($data['id'] ?? 0);
            Database::execute('DELETE FROM departments WHERE id = ?', [$id]);
            json_success([], 'واحد سازمانی حذف شد.');
            break;
        }

        case 'projects_list': {
            $rows = Database::fetchAll(
                'SELECT p.*, d.name AS department_name, o.full_name AS owner_name FROM projects p
                 LEFT JOIN departments d ON d.id = p.department_id
                 LEFT JOIN users o ON o.id = p.owner_id
                 ORDER BY p.id DESC'
            );
            foreach ($rows as &$p) {
                $p['id'] = (int) $p['id'];
                $p['department_id'] = $p['department_id'] !== null ? (int) $p['department_id'] : null;
                $p['owner_id'] = $p['owner_id'] !== null ? (int) $p['owner_id'] : null;
                $p['start_jalali'] = Jalali::toDateString($p['start_date'] ?? null, true);
                $p['due_jalali'] = Jalali::toDateString($p['due_date'] ?? null, true);
                $p['due_human'] = Jalali::toHuman($p['due_date'] ?? null, true, true);
                $p['tasks_total'] = (int) Database::fetchValue('SELECT COUNT(*) FROM tasks WHERE project_id = ? AND is_archived=0', [(int) $p['id']], 0);
                $p['tasks_done'] = (int) Database::fetchValue("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND is_archived=0 AND status='done'", [(int) $p['id']], 0);
                $p['progress'] = $p['tasks_total'] > 0 ? (int) round(($p['tasks_done'] / $p['tasks_total']) * 100) : 0;
            }
            unset($p);
            json_success(['projects' => $rows]);
            break;
        }

        case 'project_create': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $name = clean_line($data['name'] ?? '', 191);
            if ($name === '') json_error('نام پروژه را وارد کنید.');
            $code = clean_line($data['code'] ?? '', 50) ?: gen_project_code();
            if (Database::fetchOne('SELECT id FROM projects WHERE code = ? LIMIT 1', [$code])) json_error('کد پروژه تکراری است.');
            $id = Database::insert(
                'INSERT INTO projects (name, code, description, color, status, department_id, owner_id, start_date, due_date, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $name, $code, clean_text($data['description'] ?? '', 5000), clean_line($data['color'] ?? '#0284c7', 20),
                    in_array(strtolower(clean_line($data['status'] ?? 'active', 20)), ['active', 'paused', 'done', 'archived'], true) ? strtolower(clean_line($data['status'], 20)) : 'active',
                    !empty($data['department_id']) ? (int) $data['department_id'] : null,
                    !empty($data['owner_id']) ? (int) $data['owner_id'] : $uid,
                    parse_date_input($data['start_date'] ?? null) ?: today_date(),
                    parse_date_input($data['due_date'] ?? null),
                    $uid, now_datetime(), now_datetime(),
                ]
            );
            log_activity($uid, 'create_project', 'project', $id, 'پروژه «' . $name . '» ایجاد شد.');
            json_success(['id' => $id], 'پروژه ایجاد شد.');
            break;
        }

        case 'project_update': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $id = (int) ($data['id'] ?? 0);
            if (!Database::fetchOne('SELECT id FROM projects WHERE id = ?', [$id])) json_error('پروژه یافت نشد.', 404);
            $sets = []; $params = [];
            if (isset($data['name'])) { $sets[] = 'name = ?'; $params[] = clean_line($data['name'], 191); }
            if (isset($data['description'])) { $sets[] = 'description = ?'; $params[] = clean_text($data['description'], 5000); }
            if (isset($data['color'])) { $sets[] = 'color = ?'; $params[] = clean_line($data['color'], 20); }
            if (isset($data['status'])) {
                $st = strtolower(clean_line($data['status'], 20));
                if (in_array($st, ['active', 'paused', 'done', 'archived'], true)) { $sets[] = 'status = ?'; $params[] = $st; }
            }
            if (array_key_exists('department_id', $data)) { $sets[] = 'department_id = ?'; $params[] = !empty($data['department_id']) ? (int) $data['department_id'] : null; }
            if (array_key_exists('owner_id', $data)) { $sets[] = 'owner_id = ?'; $params[] = !empty($data['owner_id']) ? (int) $data['owner_id'] : null; }
            if (array_key_exists('start_date', $data)) { $sets[] = 'start_date = ?'; $params[] = parse_date_input($data['start_date']); }
            if (array_key_exists('due_date', $data)) { $sets[] = 'due_date = ?'; $params[] = parse_date_input($data['due_date']); }
            if (empty($sets)) json_error('موردی برای ویرایش ارسال نشده است.');
            $sets[] = 'updated_at = ?'; $params[] = now_datetime(); $params[] = $id;
            Database::execute('UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            json_success([], 'پروژه به‌روزرسانی شد.');
            break;
        }

        case 'project_delete': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $id = (int) ($data['id'] ?? 0);
            Database::execute('DELETE FROM projects WHERE id = ?', [$id]);
            json_success([], 'پروژه حذف شد.');
            break;
        }

        case 'attendance_today': {
            $today = today_date();
            $att = Database::fetchOne('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1', [$uid, $today]);
            json_success(['attendance' => $att ? [
                'id' => (int) $att['id'], 'work_date' => $att['work_date'],
                'work_date_jalali' => Jalali::toDateString($att['work_date'], true),
                'check_in' => $att['check_in'], 'check_out' => $att['check_out'],
                'check_in_fa' => fa_digits(substr((string) $att['check_in'], 11, 5)),
                'check_out_fa' => $att['check_out'] ? fa_digits(substr((string) $att['check_out'], 11, 5)) : null,
                'total_minutes' => (int) $att['total_minutes'],
                'total_fa' => format_minutes_fa((int) $att['total_minutes']),
                'work_mode' => $att['work_mode'], 'work_mode_label' => work_mode_label((string) $att['work_mode']),
                'note' => $att['note'],
            ] : null]);
            break;
        }

        case 'attendance_clock_in': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $today = today_date();
            $exists = Database::fetchOne('SELECT id FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1', [$uid, $today]);
            if ($exists) json_error('امروز قبلاً ساعت ورود ثبت شده است.');
            $mode = strtolower(clean_line($data['work_mode'] ?? 'office', 20));
            if (!in_array($mode, ['office', 'remote', 'mission'], true)) $mode = 'office';
            $id = Database::insert(
                'INSERT INTO attendance (user_id, work_date, check_in, check_out, total_minutes, work_mode, note, created_at) VALUES (?, ?, ?, NULL, 0, ?, ?, ?)',
                [$uid, $today, now_datetime(), $mode, clean_line($data['note'] ?? '', 300), now_datetime()]
            );
            log_activity($uid, 'clock_in', 'attendance', $id, 'ساعت ورود ثبت شد.');
            json_success(['id' => $id], '✅ ساعت ورود ثبت شد. روز کاری خوبی داشته باشید!');
            break;
        }

        case 'attendance_manual': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $targetId = $isManager && !empty($data['user_id']) ? (int) $data['user_id'] : $uid;
            if ($targetId !== $uid && !$isManager) json_error('مجوز ثبت حضور برای سایر کارمندان را ندارید.', 403);
            if (!Database::fetchOne('SELECT id FROM users WHERE id = ?', [$targetId])) json_error('کارمند یافت نشد.', 404);
            $workDate = parse_date_input($data['work_date'] ?? null) ?: today_date();
            $checkIn = parse_time_input($data['check_in'] ?? null);
            $checkOut = parse_time_input($data['check_out'] ?? null);
            if (!$checkIn) json_error('ساعت ورود را به صورت معتبر (مثلاً ۰۸:۳۰) وارد کنید.');
            $inDt = $workDate . ' ' . $checkIn . ':00';
            $outDt = $checkOut ? $workDate . ' ' . $checkOut . ':00' : null;
            if ($outDt !== null && $outDt < $inDt) json_error('ساعت خروج نمی‌تواند قبل از ساعت ورود باشد.');
            $mins = $outDt !== null ? max(0, (int) round((strtotime($outDt) - strtotime($inDt)) / 60)) : 0;
            $mode = strtolower(clean_line($data['work_mode'] ?? 'office', 20));
            if (!in_array($mode, ['office', 'remote', 'mission'], true)) $mode = 'office';
            $note = clean_line($data['note'] ?? '', 300);
            $existing = Database::fetchOne('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1', [$targetId, $workDate]);
            if ($existing) {
                Database::execute(
                    'UPDATE attendance SET check_in = ?, check_out = ?, total_minutes = ?, work_mode = ?, note = ? WHERE id = ?',
                    [$inDt, $outDt, $mins, $mode, $note, (int) $existing['id']]
                );
                $recordId = (int) $existing['id'];
                $msg = 'سابقه حضور ' . Jalali::toHuman($workDate, true, true) . ' به‌روزرسانی شد.';
            } else {
                $recordId = Database::insert(
                    'INSERT INTO attendance (user_id, work_date, check_in, check_out, total_minutes, work_mode, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$targetId, $workDate, $inDt, $outDt, $mins, $mode, $note, now_datetime()]
                );
                $msg = 'حضور برای ' . Jalali::toHuman($workDate, true, true) . ' ثبت شد.';
            }
            log_activity($uid, 'attendance_manual', 'attendance', $recordId, $msg);
            json_success(['id' => $recordId, 'total_minutes' => $mins, 'total_fa' => format_minutes_fa($mins)], $msg);
            break;
        }

        case 'attendance_clock_out': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $today = today_date();
            $att = Database::fetchOne('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1', [$uid, $today]);
            if (!$att) json_error('ابتدا ساعت ورود را ثبت کنید.');
            if (!empty($att['check_out'])) json_error('ساعت خروج امروز قبلاً ثبت شده است.');
            $mins = max(0, (int) round((time() - strtotime((string) $att['check_in'])) / 60));
            Database::execute(
                'UPDATE attendance SET check_out = ?, total_minutes = ? WHERE id = ?',
                [now_datetime(), $mins, (int) $att['id']]
            );
            log_activity($uid, 'clock_out', 'attendance', (int) $att['id'], 'ساعت خروج ثبت شد (' . format_minutes_fa($mins) . ').');
            json_success(['minutes' => $mins], '✅ ساعت خروج ثبت شد. مدت حضور امروز: ' . format_minutes_fa($mins));
            break;
        }

        case 'attendance_list': {
            $jy = (int) ($data['jy'] ?? 0);
            $jm = (int) ($data['jm'] ?? 0);
            if ($jy <= 0 || $jm < 1 || $jm > 12) {
                [$jy, $jm] = Jalali::todayArray();
            }
            $first = Jalali::jalaliToGregorianString($jy, $jm, 1);
            $last = Jalali::jalaliToGregorianString($jy, $jm, Jalali::daysInMonth($jy, $jm));
            $targetUser = $isManager && !empty($data['user_id']) ? (int) $data['user_id'] : $uid;
            $rows = Database::fetchAll(
                'SELECT * FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date ASC',
                [$targetUser, $first, $last]
            );
            $totalMin = 0;
            foreach ($rows as &$r) {
                $totalMin += (int) $r['total_minutes'];
                $r['id'] = (int) $r['id'];
                $r['work_date_jalali'] = Jalali::toDateString($r['work_date'], true);
                $r['work_date_human'] = Jalali::toHuman($r['work_date'], true, true);
                $r['check_in_fa'] = fa_digits(substr((string) $r['check_in'], 11, 5));
                $r['check_out_fa'] = $r['check_out'] ? fa_digits(substr((string) $r['check_out'], 11, 5)) : '—';
                $r['total_fa'] = format_minutes_fa((int) $r['total_minutes']);
                $r['work_mode_label'] = work_mode_label((string) $r['work_mode']);
            }
            unset($r);
            json_success([
                'rows' => $rows,
                'month_title' => Jalali::monthName($jm) . ' ' . fa_digits($jy),
                'total_minutes' => $totalMin,
                'total_fa' => format_minutes_fa($totalMin),
                'days_present' => count($rows),
            ]);
            break;
        }

        case 'attendance_team': {
            require_manager();
            $today = today_date();
            $rows = Database::fetchAll(
                'SELECT u.id, u.full_name, u.employee_code, u.avatar_color, d.name AS department_name, a.check_in, a.check_out, a.total_minutes, a.work_mode
                 FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = ?
                 WHERE u.is_active = 1 ORDER BY u.id ASC',
                [$today]
            );
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['present'] = !empty($r['check_in']);
                $r['check_in_fa'] = $r['check_in'] ? fa_digits(substr((string) $r['check_in'], 11, 5)) : '—';
                $r['check_out_fa'] = $r['check_out'] ? fa_digits(substr((string) $r['check_out'], 11, 5)) : '—';
                $r['total_fa'] = format_minutes_fa((int) ($r['total_minutes'] ?? 0));
            }
            unset($r);
            json_success(['team' => $rows, 'date_jalali' => Jalali::toDateString($today, true), 'date_human' => Jalali::toHuman($today, true, true)]);
            break;
        }

        case 'leaves_list': {
            if ($isManager) {
                $rows = Database::fetchAll(
                    'SELECT l.*, u.full_name AS user_name, u.employee_code, u.avatar_color FROM leave_requests l JOIN users u ON u.id = l.user_id ORDER BY CASE l.status WHEN \'pending\' THEN 0 ELSE 1 END, l.start_date DESC LIMIT 300'
                );
            } else {
                $rows = Database::fetchAll(
                    'SELECT l.*, u.full_name AS user_name, u.employee_code, u.avatar_color FROM leave_requests l JOIN users u ON u.id = l.user_id WHERE l.user_id = ? ORDER BY l.start_date DESC LIMIT 300',
                    [$uid]
                );
            }
            foreach ($rows as &$l) {
                $l['id'] = (int) $l['id'];
                $l['type_label'] = leave_type_label((string) $l['leave_type']);
                $l['status_label'] = leave_status_label((string) $l['status']);
                $l['start_jalali'] = Jalali::toDateString($l['start_date'], true);
                $l['end_jalali'] = Jalali::toDateString($l['end_date'], true);
                $l['start_human'] = Jalali::toHuman($l['start_date'], true, true);
                $l['end_human'] = Jalali::toHuman($l['end_date'], true, true);
                $l['hours_fa'] = fa_digits($l['hours']);
                $l['created_human'] = Jalali::toDateTimeHuman($l['created_at'] ?? null);
            }
            unset($l);
            json_success(['leaves' => $rows]);
            break;
        }

        case 'leave_create': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $type = strtolower(clean_line($data['leave_type'] ?? 'daily', 20));
            if (!in_array($type, ['daily', 'hourly', 'sick', 'mission'], true)) $type = 'daily';
            $start = parse_date_input($data['start_date'] ?? null);
            $end = parse_date_input($data['end_date'] ?? null) ?: $start;
            $reason = clean_text($data['reason'] ?? '', 3000);
            if (!$start || !$end) json_error('تاریخ شروع و پایان مرخصی را به صورت شمسی وارد کنید (مثلاً ۱۴۰۵/۰۹/۰۱).');
            if ($end < $start) json_error('تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.');
            if ($reason === '') json_error('دلیل درخواست مرخصی را بنویسید.');
            $hours = max(0, (float) en_digits((string) ($data['hours'] ?? 0)));
            $id = Database::insert(
                'INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, hours, reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?)',
                [$uid, $type, $start, $end, $hours, $reason, now_datetime()]
            );
            log_activity($uid, 'leave_request', 'leave', $id, 'درخواست مرخصی ثبت شد.');
            // اطلاع به مدیران
            $managers = Database::fetchAll("SELECT id FROM users WHERE role IN ('admin','manager') AND is_active=1");
            foreach ($managers as $m) {
                create_notification((int) $m['id'], $uid, 'leave_pending', 'درخواست مرخصی جدید', $user['full_name'] . ' درخواست ' . leave_type_label($type) . ' از ' . Jalali::toHuman($start, true, true) . ' ثبت کرد.', 'leave', $id);
            }
            json_success(['id' => $id], 'درخواست مرخصی با موفقیت ثبت شد و در انتظار تأیید است.');
            break;
        }

        case 'leave_review': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $id = (int) ($data['id'] ?? 0);
            $status = strtolower(clean_line($data['status'] ?? '', 20));
            if (!in_array($status, ['approved', 'rejected'], true)) json_error('وضعیت بررسی نامعتبر است.');
            $leave = Database::fetchOne('SELECT * FROM leave_requests WHERE id = ?', [$id]);
            if (!$leave) json_error('درخواست مرخصی یافت نشد.', 404);
            Database::execute(
                'UPDATE leave_requests SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?',
                [$status, $uid, clean_line($data['review_note'] ?? '', 400), now_datetime(), $id]
            );
            create_notification((int) $leave['user_id'], $uid, 'leave_reviewed', $status === 'approved' ? 'مرخصی شما تأیید شد ✅' : 'مرخصی شما رد شد', 'درخواست مرخصی شما ' . leave_status_label($status) . ' شد.', 'leave', $id);
            log_activity($uid, 'leave_review', 'leave', $id, 'درخواست مرخصی ' . leave_status_label($status) . ' شد.');
            json_success([], 'درخواست مرخصی ' . leave_status_label($status) . ' شد.');
            break;
        }

        case 'announcements_list': {
            $rows = Database::fetchAll(
                'SELECT a.*, u.full_name AS creator_name FROM announcements a JOIN users u ON u.id = a.created_by
                 WHERE (a.department_id IS NULL OR a.department_id = ?)
                 ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 100',
                [$user['department_id'] ?? 0]
            );
            foreach ($rows as &$a) {
                $a['id'] = (int) $a['id'];
                $a['is_pinned'] = (int) $a['is_pinned'];
                $a['created_human'] = Jalali::toDateTimeHuman($a['created_at'] ?? null);
                $a['created_ago'] = Jalali::timeAgo($a['created_at'] ?? null);
                $a['expires_jalali'] = Jalali::toDateString($a['expires_at'] ?? null, true);
            }
            unset($a);
            json_success(['announcements' => $rows]);
            break;
        }

        case 'announcement_create': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $title = clean_line($data['title'] ?? '', 220);
            $body = clean_text($data['body'] ?? '', 8000);
            if ($title === '' || $body === '') json_error('عنوان و متن اطلاعیه الزامی است.');
            $prio = strtolower(clean_line($data['priority'] ?? 'normal', 20));
            if (!in_array($prio, ['normal', 'important', 'urgent'], true)) $prio = 'normal';
            $id = Database::insert(
                'INSERT INTO announcements (title, body, priority, department_id, is_pinned, created_by, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, $body, $prio, !empty($data['department_id']) ? (int) $data['department_id'] : null, !empty($data['is_pinned']) ? 1 : 0, $uid, parse_date_input($data['expires_at'] ?? null), now_datetime()]
            );
            log_activity($uid, 'announcement', 'announcement', $id, 'اطلاعیه «' . $title . '» منتشر شد.');
            json_success(['id' => $id], 'اطلاعیه منتشر شد.');
            break;
        }

        case 'announcement_delete': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_manager();
            $id = (int) ($data['id'] ?? 0);
            Database::execute('DELETE FROM announcements WHERE id = ?', [$id]);
            json_success([], 'اطلاعیه حذف شد.');
            break;
        }

        case 'notifications_list': {
            $rows = Database::fetchAll(
                'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
                [$uid]
            );
            foreach ($rows as &$n) {
                $n['id'] = (int) $n['id'];
                $n['is_read'] = (int) $n['is_read'];
                $n['created_human'] = Jalali::toDateTimeHuman($n['created_at'] ?? null);
                $n['created_ago'] = Jalali::timeAgo($n['created_at'] ?? null);
            }
            unset($n);
            json_success(['notifications' => $rows]);
            break;
        }

        case 'notification_read': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $id = (int) ($data['id'] ?? 0);
            Database::execute('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, $uid]);
            json_success([]);
            break;
        }

        case 'notifications_read_all': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            Database::execute('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$uid]);
            json_success([], 'همه اعلان‌ها خوانده شد.');
            break;
        }

        case 'activity_list': {
            require_manager();
            $rows = Database::fetchAll(
                'SELECT l.*, u.full_name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC LIMIT 150'
            );
            foreach ($rows as &$l) {
                $l['id'] = (int) $l['id'];
                $l['created_human'] = Jalali::toDateTimeHuman($l['created_at'] ?? null);
                $l['created_ago'] = Jalali::timeAgo($l['created_at'] ?? null);
            }
            unset($l);
            json_success(['logs' => $rows]);
            break;
        }

        case 'calendar_month': {
            $jy = (int) ($data['jy'] ?? 0);
            $jm = (int) ($data['jm'] ?? 0);
            if ($jy <= 0 || $jm < 1 || $jm > 12) [$jy, $jm] = Jalali::todayArray();
            $matrix = Jalali::buildMonthMatrix($jy, $jm);
            $tasks = Database::fetchAll(
                'SELECT t.id, t.title, t.status, t.priority, t.due_date, t.due_time, t.assigned_to, a.full_name AS assignee_name, p.color AS project_color
                 FROM tasks t LEFT JOIN users a ON a.id = t.assigned_to LEFT JOIN projects p ON p.id = t.project_id
                 WHERE t.is_archived = 0 AND t.due_date BETWEEN ? AND ?
                 ORDER BY t.due_date ASC',
                [$matrix['first_greg'], $matrix['last_greg']]
            );
            if (!$isManager) {
                $tasks = array_values(array_filter($tasks, fn($t) => (int) $t['assigned_to'] === $uid || (int) ($t['created_by'] ?? 0) === $uid));
            }
            $byDate = [];
            foreach ($tasks as $t) {
                $d = substr((string) $t['due_date'], 0, 10);
                $byDate[$d][] = [
                    'id' => (int) $t['id'], 'title' => $t['title'], 'status' => $t['status'],
                    'status_label' => task_status_label((string) $t['status']),
                    'priority' => $t['priority'], 'priority_label' => task_priority_label((string) $t['priority']),
                    'assignee_name' => $t['assignee_name'] ?? '', 'project_color' => $t['project_color'] ?? '#0f766e',
                    'due_time_fa' => !empty($t['due_time']) ? fa_digits((string) $t['due_time']) : '',
                ];
            }
            foreach ($matrix['days'] as &$day) {
                $day['tasks'] = $byDate[$day['gregorian']] ?? [];
            }
            unset($day);
            json_success(['calendar' => $matrix]);
            break;
        }

        case 'assignable': {
            json_success(['users' => Auth::assignableUsers()]);
            break;
        }

        case 'profile_update': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $sets = []; $params = [];
            if (isset($data['full_name'])) { $sets[] = 'full_name = ?'; $params[] = clean_line($data['full_name'], 191); }
            if (isset($data['email'])) { $sets[] = 'email = ?'; $params[] = clean_line($data['email'], 191); }
            if (isset($data['phone'])) { $sets[] = 'phone = ?'; $params[] = clean_line($data['phone'], 30); }
            if (isset($data['position_title'])) { $sets[] = 'position_title = ?'; $params[] = clean_line($data['position_title'], 150); }
            if (isset($data['bio'])) { $sets[] = 'bio = ?'; $params[] = clean_text($data['bio'], 2000); }
            if (empty($sets)) json_error('موردی برای ویرایش ارسال نشده است.');
            $sets[] = 'updated_at = ?'; $params[] = now_datetime(); $params[] = $uid;
            Database::execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            json_success(['user' => Auth::refresh()], 'پروفایل به‌روزرسانی شد.');
            break;
        }

        case 'password_change': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            $current = (string) ($data['current_password'] ?? '');
            $new = (string) ($data['new_password'] ?? '');
            $confirm = (string) ($data['new_password_confirm'] ?? '');
            if (!password_verify($current, (string) $user['password_hash'])) json_error('رمز عبور فعلی نادرست است.');
            if (mb_strlen($new, 'UTF-8') < 6) json_error('رمز عبور جدید باید حداقل ۶ کاراکتر باشد.');
            if ($new !== $confirm) json_error('تکرار رمز عبور جدید مطابقت ندارد.');
            Database::execute('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), now_datetime(), $uid]);
            log_activity($uid, 'password_change', 'user', $uid, 'رمز عبور تغییر کرد.');
            json_success([], 'رمز عبور با موفقیت تغییر کرد.');
            break;
        }

        case 'settings_get': {
            require_admin();
            json_success(['settings' => [
                'company_name' => get_setting('company_name', APP_NAME),
                'company_slogan' => get_setting('company_slogan', ''),
                'work_start_time' => get_setting('work_start_time', '08:30'),
                'work_end_time' => get_setting('work_end_time', '17:00'),
                'allow_employee_self_task' => get_setting('allow_employee_self_task', '1'),
                'allow_employee_peer_task' => get_setting('allow_employee_peer_task', '0'),
                'ai_provider' => get_setting('ai_provider', AI_PROVIDER),
                'ai_auto_create' => get_setting('ai_auto_create', '0'),
                'groq_api_key' => get_setting('groq_api_key', GROQ_API_KEY),
                'gemini_api_key' => get_setting('gemini_api_key', GEMINI_API_KEY),
            ]]);
            break;
        }

        case 'settings_save': {
            if ($method !== 'POST') json_error('متد نامعتبر است.', 405);
            require_admin();
            $allowed = ['company_name', 'company_slogan', 'work_start_time', 'work_end_time', 'allow_employee_self_task', 'allow_employee_peer_task', 'ai_provider', 'ai_auto_create', 'groq_api_key', 'gemini_api_key'];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $data)) {
                    $val = (string) $data[$key];
                    if (in_array($key, ['company_name', 'company_slogan'], true)) $val = clean_line($val, 300);
                    if ($key === 'ai_provider') {
                        $val = strtolower(clean_line($val, 30));
                        if (!in_array($val, ['auto', 'groq', 'gemini', 'pollinations', 'local'], true)) $val = 'auto';
                    }
                    set_setting($key, $val);
                }
            }
            log_activity($uid, 'settings', 'system', 1, 'تنظیمات سیستم و هوش مصنوعی به‌روزرسانی شد.');
            json_success([], 'تنظیمات ذخیره شد.');
            break;
        }

        case 'db_status': {
            require_admin();
            $status = Database::statusInfo();
            $status['fallback_reason'] = Database::fallbackReason();
            $status['php_version'] = PHP_VERSION;
            $status['timezone'] = APP_TIMEZONE;
            $status['today_jalali'] = Jalali::toDateString(today_date(), true);
            json_success(['db' => $status]);
            break;
        }

        default:
            json_error('عملیات نامعتبر است: ' . $action, 404);
    }
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    if (APP_DEBUG) {
        json_error('خطای سرور: ' . $e->getMessage(), 500);
    }
    json_error('خطای غیرمنتظره در سرور رخ داد. لطفاً بعداً تلاش کنید.', 500);
}
