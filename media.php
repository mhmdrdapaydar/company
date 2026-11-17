<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'برای مشاهده این فایل ابتدا وارد سامانه شوید.';
    exit;
}

$type = strtolower((string) ($_GET['type'] ?? ''));
$file = basename((string) ($_GET['file'] ?? ''));
$download = (($_GET['download'] ?? '0') === '1');

if ($file === '' || str_contains($file, '..') || str_contains($file, '/') || str_contains($file, '\\')) {
    http_response_code(400);
    echo 'نام فایل نامعتبر است.';
    exit;
}

$allowed = false;
$fullPath = '';
$mime = 'application/octet-stream';
$downloadName = $file;

if ($type === 'voice') {
    $fullPath = VOICES_PATH . '/' . $file;
    // بررسی دسترسی: فایل باید متعلق به تسک یا کامنتی باشد که کاربر مجاز به مشاهده آن است
    $task = Database::fetchOne('SELECT * FROM tasks WHERE voice_file = ? LIMIT 1', [$file]);
    if ($task && Auth::canViewTask($task)) {
        $allowed = true;
        $downloadName = 'voice-task-' . $task['task_code'] . '.webm';
    } else {
        $comment = Database::fetchOne('SELECT * FROM task_comments WHERE voice_file = ? LIMIT 1', [$file]);
        if ($comment) {
            $ctask = Database::fetchOne('SELECT * FROM tasks WHERE id = ?', [(int) $comment['task_id']]);
            if ($ctask && Auth::canViewTask($ctask)) {
                $allowed = true;
                $downloadName = 'voice-comment-' . $comment['id'] . '.webm';
            }
        }
    }
    $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg', 'opus' => 'audio/ogg',
        'm4a', 'mp4' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac',
        default => 'audio/webm',
    };
} elseif ($type === 'attachment') {
    $fullPath = ATTACHMENTS_PATH . '/' . $file;
    $att = Database::fetchOne('SELECT * FROM task_attachments WHERE stored_name = ? LIMIT 1', [$file]);
    if ($att) {
        $task = Database::fetchOne('SELECT * FROM tasks WHERE id = ?', [(int) $att['task_id']]);
        if ($task && Auth::canViewTask($task)) {
            $allowed = true;
            $mime = (string) ($att['mime_type'] ?: 'application/octet-stream');
            $downloadName = (string) $att['original_name'];
        }
    }
} else {
    http_response_code(400);
    echo 'نوع فایل نامعتبر است.';
    exit;
}

if (!$allowed || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'فایل یافت نشد یا به آن دسترسی ندارید.';
    exit;
}

$size = filesize($fullPath);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
if ($download) {
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($downloadName));
} else {
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($downloadName));
}

// پشتیبانی پخش آنلاین و ادامه پخش (Range)
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
    $start = (int) $m[1];
    $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
    if ($start <= $end) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . ($end - $start + 1));
        $fp = fopen($fullPath, 'rb');
        if ($fp) {
            fseek($fp, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(8192, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($fp);
        }
        exit;
    }
}

readfile($fullPath);
exit;
