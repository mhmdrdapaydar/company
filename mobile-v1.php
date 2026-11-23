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
];
if (!isset($methods[$action])) json_error('Unknown action.', 404);
if ($method !== $methods[$action]) json_error('Method not allowed.', 405);
$MOBILE_TOKEN = MobileAuth::authenticate();
if (!$MOBILE_TOKEN) { header('WWW-Authenticate: Bearer'); json_error('نشست دستگاه نامعتبر یا منقضی شده است.', 401); }
Auth::useMobileUser($MOBILE_TOKEN['user_id']);
if ($action === 'logout') { MobileAuth::revoke($MOBILE_TOKEN['id']); json_success([], 'خروج انجام شد.'); }
// The shared action handlers retain all existing role checks and transactional behaviour.
require __DIR__ . '/api.php';

} catch (Throwable $e) {
    error_log('Mobile API failed: ' . get_class($e));
    json_error('خطای داخلی سرور رخ داد.', 500);
}
