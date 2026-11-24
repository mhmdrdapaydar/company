<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
$info = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) (request_data()['csrf_token'] ?? '');
    if (!verify_csrf($token)) {
        $error = 'توکن امنیتی منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.';
    } else {
        $identifier = clean_line(request_data()['username'] ?? '', 100);
        $password = (string) (request_data()['password'] ?? '');
        $result = Auth::attempt($identifier, $password);
        if (!empty($result['ok'])) {
            header('Location: index.php');
            exit;
        }
        $error = (string) ($result['message'] ?? 'ورود ناموفق بود.');
    }
}

$companyName = get_setting('company_name', APP_NAME);
$companySlogan = get_setting('company_slogan', 'سامانه یکپارچه مدیریت تسک‌ها و پروژه‌های شرکت');
$todayHuman = Jalali::toHuman(today_date(), true, true);
$todayJalali = Jalali::toDateString(today_date(), true);
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f766e">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/icons/app-192.png">
<title>ورود به سامانه | <?= e($companyName) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="brand-logo">هـ</div>
      <div>
        <h1><?= e($companyName) ?></h1>
        <p><?= e($companySlogan) ?></p>
      </div>
    </div>
    <div class="login-date">📅 امروز: <strong><?= e($todayHuman) ?></strong> <span>(<?= e($todayJalali) ?> شمسی)</span></div>

    <?php if ($error !== ''): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>
    <?php if ($info !== ''): ?><div class="alert alert-info">ℹ️ <?= e($info) ?></div><?php endif; ?>

    <form method="post" action="login.php" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <label class="field">
        <span>شماره تلفن، نام کاربری، ایمیل یا کد پرسنلی</span>
        <input type="text" name="username" required autofocus placeholder="مثلاً شماره تلفن یا نام کاربری" value="<?= e(clean_line($_POST['username'] ?? '', 100)) ?>">
      </label>
      <label class="field">
        <span>رمز عبور</span>
        <input type="password" name="password" required placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password">
      </label>
      <button type="submit" class="btn btn-primary btn-block btn-lg">🔐 ورود به پنل مدیریت</button>
    </form>

    <div class="login-foot">
      <span>تقویم شمسی • زبان فارسی • پایگاه داده SQL با پشتیبانی کامل فارسی</span>
      <span>نسخه <?= e(APP_VERSION) ?></span>
    </div>
  </div>
</div>
<script>
(function(){
  try{
    var t = localStorage.getItem('hamkar_theme');
    if(t){ document.documentElement.setAttribute('data-theme', t); }
  }catch(e){}
})();
</script>
</body>
</html>
