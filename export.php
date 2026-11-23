<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$type = strtolower((string) ($_GET['type'] ?? 'tasks'));
$format = strtolower((string) ($_GET['format'] ?? 'csv'));
$companyName = get_setting('company_name', APP_NAME);
$todayHuman = Jalali::toHuman(today_date(), true, true);

/**
 * 🔒 خنثی‌سازی تزریق فرمول در CSV (Excel/Sheets CSV Injection)
 *
 * اگر مقدار با = + - @ یا TAB/CR شروع شود، اکسل آن را فرمول اجرا می‌کند.
 * راه‌حل: یک آپاستروف ابتدای مقدار (اکسل آن را به‌عنوان «متن» می‌شناسد).
 *
 * ⚠️ نکته مهم: fputcsv پی‌اچ‌پی وقتی مقدار با «'» شروع شود آن را در گیومه
 * نمی‌گذارد، بنابراین اگر مقدار ویرگول داشته باشد سطر CSV خراب می‌شود.
 * به همین دلیل بعد از افزودن آپاستروف، در صورت نیاز خودمان گیومه می‌گذاریم.
 */
function csv_neutralize(mixed $value): string
{
    $v = (string) $value;
    if ($v === '') {
        return '';
    }
    if (preg_match('/^[=\+\-@\t\r]/', $v)) {
        $v = "'" . $v;
    }
    // اگر fputcsv آن را گیومه‌گذاری نکند ولی نیاز به گیومه دارد، خودمان انجام می‌دهیم
    if (strpbrk($v, ",\"\n\r\t") !== false) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

function export_csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    // BOM برای نمایش صحیح فارسی در اکسل
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('csv_neutralize', $headers));
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_neutralize', $row));
    }
    fclose($out);
    exit;
}

function export_print_page(string $title, string $subtitle, array $headers, array $rows, string $companyName, string $todayHuman): never
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>' . e($title) . '</title>';
    echo '<style>body{font-family:Tahoma,Vazirmatn,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px} .sheet{background:#fff;max-width:1100px;margin:auto;border:1px solid #e2e8f0;border-radius:14px;padding:24px} h1{font-size:20px;margin:0 0 6px} .sub{color:#64748b;font-size:13px;margin-bottom:16px} table{width:100%;border-collapse:collapse;font-size:13px} th,td{border:1px solid #e2e8f0;padding:8px 10px;text-align:right} th{background:#0f766e;color:#fff} tr:nth-child(even) td{background:#f8fafc} .actions{margin-bottom:14px} button{background:#0f766e;color:#fff;border:0;padding:8px 16px;border-radius:8px;cursor:pointer} @media print{body{background:#fff;padding:0}.actions{display:none}.sheet{border:0;max-width:none}}</style></head><body><div class="sheet">';
    echo '<div class="actions"><button onclick="window.print()">🖨️ چاپ / ذخیره PDF</button></div>';
    echo '<h1>' . e($title) . '</h1><div class="sub">' . e($companyName) . ' • تاریخ گزارش: ' . e($todayHuman) . ' (' . e(Jalali::toDateString(today_date(), true)) . ' شمسی) • ' . e($subtitle) . '</div>';
    echo '<table><thead><tr>';
    foreach ($headers as $h) echo '<th>' . e($h) . '</th>';
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($headers) . '" style="text-align:center;color:#64748b">رکوردی یافت نشد.</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) echo '<td>' . e((string) $cell) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></body></html>';
    exit;
}

$stamp = date('Ymd_His');

if ($type === 'tasks') {
    $scope = Auth::isManager() ? 'all' : 'mine';
    if (Auth::isManager() && (($_GET['scope'] ?? '') === 'mine')) $scope = 'mine';
    if ($scope === 'mine') {
        $rowsDb = Database::fetchAll(
            'SELECT t.*, a.full_name AS assignee_name, c.full_name AS creator_name, p.name AS project_name
             FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN users c ON c.id = t.created_by
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE (t.assigned_to = ? OR t.created_by = ?) AND t.is_archived = 0
             ORDER BY t.due_date ASC, t.id DESC',
            [$user['id'], $user['id']]
        );
    } else {
        $rowsDb = Database::fetchAll(
            'SELECT t.*, a.full_name AS assignee_name, c.full_name AS creator_name, p.name AS project_name
             FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN users c ON c.id = t.created_by
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.is_archived = 0
             ORDER BY t.due_date ASC, t.id DESC'
        );
    }
    $headers = ['کد تسک', 'عنوان', 'وضعیت', 'اولویت', 'پیشرفت (%)', 'مسئول', 'ایجادکننده', 'تاریخ شروع (شمسی)', 'سررسید (شمسی)', 'ساعت سررسید', 'زمان تخمینی', 'دسته‌بندی', 'منشأ ایجاد'];
    $rows = [];
    foreach ($rowsDb as $r) {
        $rows[] = [
            $r['task_code'], $r['title'], task_status_label((string) $r['status']), task_priority_label((string) $r['priority']),
            fa_digits((int) $r['progress']), $r['assignee_name'] ?? '', $r['creator_name'] ?? '',
            Jalali::toDateString($r['start_date'], true), Jalali::toDateString($r['due_date'], true),
            $r['due_time'] ? fa_digits($r['due_time']) : '', format_minutes_fa((int) $r['estimated_minutes']),
            $r['category'] ?? '', ($r['created_via'] === 'voice_ai' ? 'تسک صوتی هوش مصنوعی' : 'دستی'),
        ];
    }
    if ($format === 'print') export_print_page('گزارش تسک‌ها و وظایف', 'همه تسک‌های فعال سامانه', $headers, $rows, $companyName, $todayHuman);
    export_csv_download('tasks_' . $stamp . '.csv', $headers, $rows);
}

if ($type === 'employees') {
    if (!Auth::isManager()) { http_response_code(403); echo 'دسترسی ندارید.'; exit; }
    $rowsDb = Database::fetchAll(
        'SELECT u.*, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id ORDER BY u.id ASC'
    );
    $headers = ['کد پرسنلی', 'نام و نام خانوادگی', 'نام کاربری', 'ایمیل', 'تلفن', 'نقش', 'سمت', 'تاریخ استخدام (شمسی)', 'وضعیت', 'آخرین ورود (شمسی)'];
    $rows = [];
    foreach ($rowsDb as $r) {
        $rows[] = [
            $r['employee_code'], $r['full_name'], $r['username'], $r['email'] ?? '', $r['phone'] ?? '',
            user_role_label((string) $r['role']), $r['position_title'] ?? '',
            Jalali::toDateString($r['hire_date'], true), ((int) $r['is_active'] === 1 ? 'فعال' : 'غیرفعال'),
            $r['last_login_at'] ? Jalali::toDateTimeHuman($r['last_login_at']) : '—',
        ];
    }
    if ($format === 'print') export_print_page('گزارش کارمندان و پرسنل شرکت', 'فهرست کامل پرسنل', $headers, $rows, $companyName, $todayHuman);
    export_csv_download('employees_' . $stamp . '.csv', $headers, $rows);
}

echo 'نوع گزارش نامعتبر است. مقادیر مجاز: tasks ، employees';
exit;
