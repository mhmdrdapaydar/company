<?php
declare(strict_types=1);
// Bearer-only entry point: a browser cookie is never treated as mobile authentication.
define('MOBILE_API', true);
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
MobileAuth::requireSecureTransport();
$action = strtolower(clean_line($_GET['action'] ?? '', 60));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($action === 'login') {
    if ($method !== 'POST') json_error('Method not allowed.', 405);
    $data = request_data();
    $identifier = clean_line($data['identifier'] ?? '', 100);
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    if ($identifier === '' || $password === '' || strlen($password) > 512) json_error('نام کاربری یا شماره تلفن و رمز عبور را وارد کنید.', 400);
    if (!MobileAuth::consumeLoginAttempt($identifier)) { header('Retry-After: 900'); json_error('تلاش بیش از حد؛ بعداً دوباره تلاش کنید.', 429); }
    $result = Auth::attempt($identifier, $password);
    if (empty($result['ok'])) json_error('نام کاربری یا رمز عبور نادرست است.', 401);
    $token = MobileAuth::issue((int) $result['user']['id']);
    json_success(['token_type' => 'Bearer', 'access_token' => $token, 'idle_timeout_seconds' => MobileAuth::IDLE_SECONDS, 'user' => $result['user']]);
}

$methods = [
    'me' => 'GET', 'tasks_list' => 'GET', 'task_get' => 'GET', 'assignable' => 'GET',
    'calendar_month' => 'GET', 'calendar_today' => 'GET', 'announcements_list' => 'GET',
    'notifications_list' => 'GET', 'notifications_unread' => 'GET',
    'task_create' => 'POST', 'task_update' => 'POST', 'task_delete' => 'POST',
    'voice_preview' => 'POST', 'voice_create' => 'POST', 'notification_read' => 'POST',
    'notifications_read_all' => 'POST', 'comment_add' => 'POST', 'logout' => 'POST',
    'push_register' => 'POST', 'push_unregister' => 'POST', 'push_receipt' => 'POST',
    'push_status' => 'GET', 'notification_get' => 'GET', 'notification_send' => 'POST',
];
if (!isset($methods[$action])) json_error('Unknown action.', 404);
if ($method !== $methods[$action]) json_error('Method not allowed.', 405);
$MOBILE_TOKEN = MobileAuth::authenticate();
if (!$MOBILE_TOKEN) { header('WWW-Authenticate: Bearer'); json_error('نشست دستگاه نامعتبر یا منقضی شده است.', 401); }
Auth::useMobileUser($MOBILE_TOKEN['user_id']);
if ($action === 'logout') { MobileAuth::revoke($MOBILE_TOKEN['id']); json_success([], 'خروج انجام شد.'); }
if (in_array($action, ['push_register', 'push_unregister', 'push_receipt', 'push_status', 'notification_get', 'notification_send'], true)) {
    $data = request_data();
    try {
        if ($action === 'push_register') {
            AndroidPush::register($MOBILE_TOKEN['id'], $MOBILE_TOKEN['user_id'], is_string($data['fcm_token'] ?? null) ? $data['fcm_token'] : '');
            json_success([], 'دستگاه برای پوش ثبت شد.');
        }
        if ($action === 'push_unregister') { AndroidPush::unregister($MOBILE_TOKEN['id'], $MOBILE_TOKEN['user_id']); json_success(); }
        if ($action === 'push_receipt') {
            if (($data['result'] ?? '') !== 'received') json_error('نتیجه دریافت نامعتبر است.', 400);
            $already = AndroidPush::receipt($MOBILE_TOKEN['id'], $MOBILE_TOKEN['user_id'], (int) ($data['notification_id'] ?? 0));
            json_success(['already_received' => $already]);
        }
        if ($action === 'notification_get') {
            $notification = Database::fetchOne('SELECT * FROM notifications WHERE id=? AND user_id=?', [(int) ($data['notification_id'] ?? 0), $MOBILE_TOKEN['user_id']]);
            if (!$notification) json_error('اعلان یافت نشد.', 404);
            $notification['id'] = (int) $notification['id']; $notification['is_read'] = (int) $notification['is_read'];
            $notification['created_human'] = Jalali::toDateTimeHuman($notification['created_at'] ?? null);
            json_success(['notification'=>$notification]);
        }
        if ($action === 'push_status') json_success(['notification_id'=>(int) ($data['notification_id'] ?? 0), 'deliveries'=>AndroidPush::status($MOBILE_TOKEN['user_id'], (int) ($data['notification_id'] ?? 0), Auth::isAdmin())]);
        if ($action === 'notification_send') {
            if (!Auth::isManager()) json_error('تنها مدیر می‌تواند اعلان ارسال کند.', 403);
            $recipient = (int) ($data['user_id'] ?? 0); $actor = $MOBILE_TOKEN['user_id'];
            $title = clean_line($data['title'] ?? '', 190); $message = clean_text($data['message'] ?? '', 500);
            if ($recipient <= 0 || $recipient === $actor || $title === '' || $message === '') json_error('گیرنده، عنوان و متن معتبر الزامی است.', 400);
            if (!Auth::canAssignTo($recipient) || !Database::fetchOne('SELECT id FROM users WHERE id=? AND is_active=1', [$recipient])) json_error('گیرنده مجاز یا فعال نیست.', 403);
            $id = Database::transaction(fn() => create_notification($recipient, $actor, 'message', $title, $message, 'message', null, true));
            json_success(['notification_id'=>$id], 'اعلان ثبت شد.');
        }
    } catch (RuntimeException $e) { json_error($e->getMessage(), in_array($e->getCode(), [400, 403, 404, 409, 503], true) ? $e->getCode() : 400); }
}
// The shared action handlers retain all existing role checks and transactional behaviour.
require __DIR__ . '/api.php';

} catch (Throwable $e) {
    error_log('Mobile API failed: ' . get_class($e));
    json_error('خطای داخلی سرور رخ داد.', 500);
}
