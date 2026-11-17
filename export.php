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
    $headers = ['کد تسک', 'عنوان', 'وضعیت', 'اولویت', 'پیشرفت (%)', 'مسئول', 'ایجادکننده', 'پروژه', 'تاریخ شروع (شمسی)', 'سررسید (شمسی)', 'ساعت سررسید', 'زمان تخمینی', 'دسته‌بندی', 'منشأ ایجاد'];
    $rows = [];
    foreach ($rowsDb as $r) {
        $rows[] = [
            $r['task_code'], $r['title'], task_status_label((string) $r['status']), task_priority_label((string) $r['priority']),
            fa_digits((int) $r['progress']), $r['assignee_name'] ?? '', $r['creator_name'] ?? '', $r['project_name'] ?? '',
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
    $headers = ['کد پرسنلی', 'نام و نام خانوادگی', 'نام کاربری', 'ایمیل', 'تلفن', 'نقش', 'سمت', 'واحد سازمانی', 'تاریخ استخدام (شمسی)', 'وضعیت', 'آخرین ورود (شمسی)'];
    $rows = [];
    foreach ($rowsDb as $r) {
        $rows[] = [
            $r['employee_code'], $r['full_name'], $r['username'], $r['email'] ?? '', $r['phone'] ?? '',
            user_role_label((string) $r['role']), $r['position_title'] ?? '', $r['department_name'] ?? '',
            Jalali::toDateString($r['hire_date'], true), ((int) $r['is_active'] === 1 ? 'فعال' : 'غیرفعال'),
            $r['last_login_at'] ? Jalali::toDateTimeHuman($r['last_login_at']) : '—',
        ];
    }
    if ($format === 'print') export_print_page('گزارش کارمندان و پرسنل شرکت', 'فهرست کامل پرسنل', $headers, $rows, $companyName, $todayHuman);
    export_csv_download('employees_' . $stamp . '.csv', $headers, $rows);
}

if ($type === 'attendance') {
    $where = '';
    $params = [];
    $isManager = Auth::isManager();

    // انتخاب کارمند: مدیر می‌تواند هر کسی را ببیند، کارمند فقط سوابق خودش
    $targetUser = $isManager && !empty($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $user['id'];
    if ($isManager && $targetUser === (int) $user['id'] && empty($_GET['user_id'])) {
        // مدیر بدون انتخاب کارمند → همه کارمندان
        $filterUser = false;
    } else {
        $filterUser = true;
    }
    if ($filterUser) {
        $where = 'WHERE a.user_id = ?';
        $params[] = $targetUser;
        // عنوان و نام فایل با نام کارمند
        $targetName = (string) Database::fetchValue('SELECT full_name FROM users WHERE id = ?', [$targetUser], '');
        if ($targetName !== '') {
            $reportTitle = 'گزارش حضور و غیاب ' . $targetName;
            $fileStem = 'attendance_' . $targetUser . '_';
        }
    }
    if (empty($fileStem)) {
        $fileStem = 'attendance_';
    }
    if (empty($reportTitle)) {
        $reportTitle = 'گزارش حضور و غیاب';
    }

    // فیلتر ماه: هم به شکل jy/jm (فرم) و هم month=1405/08 پذیرفته می‌شود
    $jy = (int) ($_GET['jy'] ?? 0);
    $jm = (int) ($_GET['jm'] ?? 0);
    if ($jy > 0 && $jm > 0) {
        $first = Jalali::jalaliToGregorianString($jy, $jm, 1);
        $last = Jalali::jalaliToGregorianString($jy, $jm, Jalali::daysInMonth($jy, $jm));
        $where .= ($where === '' ? 'WHERE ' : ' AND ') . 'a.work_date BETWEEN ? AND ?';
        $params[] = $first;
        $params[] = $last;
        $reportTitle .= ' — ' . Jalali::monthName($jm) . ' ' . fa_digits($jy);
    } else {
        $month = (string) ($_GET['month'] ?? '');
        if ($month !== '') {
            $greg = Jalali::parseToGregorian($month . '/01');
            if ($greg) {
                [$gy, $gm] = array_map('intval', explode('-', $greg));
                if (Database::isSqlite()) {
                    $where .= ($where === '' ? 'WHERE ' : ' AND ') . "strftime('%Y-%m', a.work_date) = ?";
                } else {
                    $where .= ($where === '' ? 'WHERE ' : ' AND ') . "DATE_FORMAT(a.work_date, '%Y-%m') = ?";
                }
                $params[] = sprintf('%04d-%02d', $gy, $gm);
            }
        }
    }
    $rowsDb = Database::fetchAll(
        "SELECT a.*, u.full_name AS user_name, u.employee_code FROM attendance a JOIN users u ON u.id = a.user_id {$where} ORDER BY a.work_date DESC, a.check_in DESC LIMIT 2000",
        $params
    );
    $headers = ['نام کارمند', 'کد پرسنلی', 'تاریخ حضور (شمسی)', 'ساعت ورود', 'ساعت خروج', 'مدت حضور', 'نوع حضور', 'یادداشت'];
    $rows = [];
    foreach ($rowsDb as $r) {
        $rows[] = [
            $r['user_name'], $r['employee_code'], Jalali::toDateString($r['work_date'], true),
            $r['check_in'] ? fa_digits(substr((string) $r['check_in'], 11, 5)) : '',
            $r['check_out'] ? fa_digits(substr((string) $r['check_out'], 11, 5)) : '—',
            format_minutes_fa((int) $r['total_minutes']), work_mode_label((string) $r['work_mode']), $r['note'] ?? '',
        ];
    }
    if ($format === 'print') export_print_page($reportTitle, 'ثبت‌های حضور روزانه', $headers, $rows, $companyName, $todayHuman);
    export_csv_download($fileStem . $stamp . '.csv', $headers, $rows);
}

if ($type === 'leaves') {
    if (!Auth::isManager()) {
        $rowsDb = Database::fetchAll(
            'SELECT l.*, u.full_name AS user_name FROM leave_requests l JOIN users u ON u.id = l.user_id WHERE l.user_id = ? ORDER BY l.start_date DESC',
            [$user['id']]
        );
    } else {
        $rowsDb = Database::fetchAll(
            'SELECT l.*, u.full_name AS user_name FROM leave_requests l JOIN users u ON u.id = l.user_id ORDER BY l.start_date DESC'
        );
    }
    $headers = ['نام کارمند', 'نوع مرخصی', 'از تاریخ (شمسی)', 'تا تاریخ (شمسی)', 'ساعات', 'وضعیت', 'دلیل', 'تاریخ بررسی (شمسی)'];
    $rows = [];
    foreach ($rowsDb as $r) {
        $rows[] = [
            $r['user_name'], leave_type_label((string) $r['leave_type']),
            Jalali::toDateString($r['start_date'], true), Jalali::toDateString($r['end_date'], true),
            fa_digits($r['hours']), leave_status_label((string) $r['status']), $r['reason'],
            $r['reviewed_at'] ? Jalali::toDateTimeHuman($r['reviewed_at']) : '—',
        ];
    }
    if ($format === 'print') export_print_page('گزارش درخواست‌های مرخصی', 'مرخصی‌ها و مأموریت‌ها', $headers, $rows, $companyName, $todayHuman);
    export_csv_download('leaves_' . $stamp . '.csv', $headers, $rows);
}

http_response_code(400);
echo 'نوع گزارش نامعتبر است. مقادیر مجاز: tasks ، employees ، attendance ، leaves';
exit;
