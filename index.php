<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$uid         = (int) $user['id'];
$isAdmin     = Auth::isAdmin();
$isManager   = Auth::isManager();
$companyName = get_setting('company_name', APP_NAME);
$slogan      = get_setting('company_slogan', 'سامانه یکپارچه مدیریت تسک‌ها، حضور و غیاب و پروژه‌ها');
$todayHuman  = Jalali::toHuman(today_date(), true, true);
$todayJalali = Jalali::toDateString(today_date(), true);
$nowTime     = fa_digits(date('H:i'));
$unread      = (int) Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$uid], 0);
$assignables = Auth::assignableUsers();
$projectsAll = Database::fetchAll("SELECT id, name, code, color FROM projects WHERE status = 'active' ORDER BY id ASC");
$departments = Database::fetchAll('SELECT id, name FROM departments ORDER BY id ASC');
$csrf        = csrf_token();
$avatarColor = (string) ($user['avatar_color'] ?? '#0f766e');
$initials    = (string) ($user['initials'] ?? '؟');
$roleLabel   = (string) ($user['role_label'] ?? '');
$deptName    = (string) ($user['department_name'] ?? 'بدون واحد');
$jobTitle    = (string) ($user['position_title'] ?? $roleLabel);
$theme       = 'light';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<meta name="description" content="پنل مدیریت شرکت، تسک‌ها، پروژه‌ها و دستیار صوتی هوش مصنوعی فارسی">
<title><?= e($companyName) ?> — پنل مدیریت شرکت</title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<script>
/* بازگردانی تم قبل از رندر برای جلوگیری از پرش */
(function(){try{var t=localStorage.getItem('hamkar_theme');if(t){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();
</script>

<div class="app">
  <!-- نوار کناری -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="brand-logo">هـ</div>
      <div>
        <h2><?= e($companyName) ?></h2>
        <p>همکار پلاس • نسخه <?= e(fa_digits(APP_VERSION)) ?></p>
      </div>
    </div>
    <nav class="nav" id="mainNav">
      <a href="#dashboard" class="active" data-section="dashboard"><span>📊</span> داشبورد</a>
      <a href="#tasks" data-section="tasks"><span>🗂️</span> تسک‌ها</a>
      <a href="#calendar" data-section="calendar"><span>📅</span> تقویم شمسی</a>
      <a href="#projects" data-section="projects"><span>🚀</span> پروژه‌ها</a>
      <?php if ($isManager): ?>
      <a href="#employees" data-section="employees"><span>👥</span> کارمندان</a>
      <a href="#departments" data-section="departments"><span>🏢</span> واحدهای سازمانی</a>
      <?php endif; ?>
      <a href="#attendance" data-section="attendance"><span>⏰</span> حضور و غیاب</a>
      <a href="#leaves" data-section="leaves"><span>🌴</span> مرخصی‌ها</a>
      <a href="#announcements" data-section="announcements"><span>📢</span> اطلاعیه‌ها</a>
      <a href="#notifications" data-section="notifications"><span>🔔</span> اعلان‌ها<?php if ($unread > 0): ?><span class="nav-badge"><?= e(fa_digits($unread)) ?></span><?php endif; ?></a>
      <?php if ($isManager): ?>
      <a href="#activity" data-section="activity"><span>📝</span> گزارش فعالیت‌ها</a>
      <?php endif; ?>
      <a href="#profile" data-section="profile"><span>👤</span> پروفایل من</a>
      <?php if ($isAdmin): ?>
      <a href="#settings" data-section="settings"><span>⚙️</span> تنظیمات سیستم</a>
      <?php endif; ?>
      <a href="export.php?type=tasks&format=print" target="_blank"><span>🖨️</span> گزارش چاپی تسک‌ها</a>
      <a href="logout.php"><span>🚪</span> خروج از حساب</a>
    </nav>
    <div class="sidebar-user">
      <div class="avatar" style="background:<?= e($avatarColor) ?>"><?= e($initials) ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:800;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string) $user['full_name']) ?></div>
        <div class="small muted"><?= e($jobTitle) ?> • <?= e($deptName) ?></div>
      </div>
    </div>
  </aside>

  <!-- بخش اصلی -->
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-toggle" id="menuToggle" title="منو">☰</button>
      <h1 id="pageTitle">داشبورد</h1>
      <div class="spacer"></div>
      <div class="clock-box">
        <div class="t" id="liveClock"><?= e($nowTime) ?></div>
        <div class="d"><?= e($todayHuman) ?></div>
      </div>
      <button class="btn btn-primary" id="btnQuickVoice" title="ثبت تسک با صدا">🎙️ تسک صوتی</button>
      <button class="btn" id="btnQuickTask" title="ثبت تسک جدید">➕ تسک جدید</button>
      <button class="icon-btn" id="btnNotifBell" title="اعلان‌ها" style="position:relative">
        🔔<span id="notifDot" class="badge badge-urgent" style="position:absolute;top:-6px;left:-6px;padding:0 6px;<?= $unread > 0 ? '' : 'display:none' ?>"><?= e(fa_digits($unread)) ?></span>
      </button>
      <button class="icon-btn" id="btnTheme" title="تغییر تم">🌙</button>
      <a class="icon-btn" id="btnFullscreen" title="نمایش تمام‌صفحه" href="#" style="text-decoration:none">⛶</a>
    </header>

    <main class="content">

      <!-- ================= داشبورد ================= -->
      <section class="section active" id="section-dashboard">
        <div class="card" style="margin-bottom:16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
          <div class="avatar avatar-lg" style="background:<?= e($avatarColor) ?>"><?= e($initials) ?></div>
          <div style="flex:1;min-width:220px">
            <h2 style="font-size:18px">سلام <?= e((string) $user['full_name']) ?> 👋</h2>
            <div class="small muted">امروز <?= e($todayHuman) ?> — خوش آمدید به <?= e($companyName) ?></div>
          </div>
          <div class="att-summary" id="dashAttendanceBox">
            <div>
              <div class="small muted">حضور امروز</div>
              <div class="att-big" id="dashCheckIn">—</div>
            </div>
            <button class="btn btn-primary" id="btnClockIn">⏰ ثبت ورود</button>
            <button class="btn" id="btnClockOut" style="display:none">🚪 ثبت خروج</button>
          </div>
        </div>

        <div class="grid grid-4" id="dashStats" style="margin-bottom:16px">
          <div class="card"><div class="loading"><div class="spinner"></div></div></div>
          <div class="card"><div class="loading"><div class="spinner"></div></div></div>
          <div class="card"><div class="loading"><div class="spinner"></div></div></div>
          <div class="card"><div class="loading"><div class="spinner"></div></div></div>
        </div>

        <div class="grid grid-2" style="margin-bottom:16px">
          <div class="card">
            <h3>📈 روند ۷ روز اخیر <span class="small muted" style="font-weight:400">(ایجاد شده / تکمیل شده)</span></h3>
            <div class="chart-box"><canvas class="chart" id="chart7d" width="800" height="260"></canvas></div>
          </div>
          <div class="card">
            <h3>⚡ توزیع اولویت تسک‌های باز</h3>
            <div class="chart-box"><canvas class="chart" id="chartPriority" width="800" height="260"></canvas></div>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="card">
            <h3>📋 تسک‌های من <a href="#tasks" class="small" style="margin-right:auto">مشاهده همه ←</a></h3>
            <div id="dashMyTasks"><div class="loading"><div class="spinner"></div></div></div>
          </div>
          <div class="card">
            <h3>🗓️ سررسیدهای نزدیک</h3>
            <div id="dashUpcoming"><div class="loading"><div class="spinner"></div></div></div>
          </div>
        </div>
      </section>

      <!-- ================= تسک‌ها ================= -->
      <section class="section" id="section-tasks">
        <div class="card" style="margin-bottom:16px">
          <div class="toolbar" style="margin-bottom:0">
            <div class="search">
              <input type="text" id="taskSearch" placeholder="جستجو در عنوان، توضیحات، کد یا برچسب تسک...">
              <span>🔍</span>
            </div>
            <select id="taskScope">
              <option value="mine">تسک‌های من</option>
              <?php if ($isManager): ?>
              <option value="team">همه تسک‌های شرکت</option>
              <option value="assigned">محول‌شده به من</option>
              <?php endif; ?>
            </select>
            <select id="taskStatusFilter">
              <option value="">همه وضعیت‌ها</option>
              <option value="todo">در انتظار</option>
              <option value="in_progress">در حال انجام</option>
              <option value="review">در بررسی</option>
              <option value="done">انجام شده</option>
            </select>
            <select id="taskPriorityFilter">
              <option value="">همه اولویت‌ها</option>
              <option value="urgent">فوری</option>
              <option value="high">زیاد</option>
              <option value="medium">متوسط</option>
              <option value="low">کم</option>
            </select>
            <select id="taskProjectFilter">
              <option value="">همه پروژه‌ها</option>
              <?php foreach ($projectsAll as $p): ?>
              <option value="<?= e((string) $p['id']) ?>"><?= e((string) $p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($isManager): ?>
            <select id="taskUserFilter">
              <option value="">همه کارمندان</option>
              <?php foreach ($assignables as $a): ?>
              <option value="<?= e((string) $a['id']) ?>"><?= e((string) $a['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="view-toggle">
              <button data-view="list" class="active">☰ لیست</button>
              <button data-view="kanban">▦ کانبان</button>
            </div>
            <button class="btn btn-primary btn-sm" id="btnNewTask">➕ تسک جدید</button>
          </div>
        </div>
        <div id="tasksListView"><div class="loading"><div class="spinner"></div>در حال دریافت تسک‌ها...</div></div>
        <div id="tasksKanbanView" class="hidden">
          <div class="kanban" id="kanbanBoard"></div>
        </div>
      </section>

      <!-- ================= تقویم ================= -->
      <section class="section" id="section-calendar">
        <div class="card">
          <div class="cal-head">
            <button class="icon-btn" id="calPrev">‹</button>
            <h3 id="calTitle">تقویم</h3>
            <button class="icon-btn" id="calNext">›</button>
            <button class="btn btn-sm" id="calToday">امروز</button>
            <div class="spacer" style="flex:1"></div>
            <button class="btn btn-primary btn-sm" id="btnCalNewTask">➕ تسک در این ماه</button>
            <span class="small muted">با کلیک روی هر روز تسک جدید با همان تاریخ ثبت کنید</span>
          </div>
          <div class="cal-grid" id="calWeekdays"></div>
          <div class="cal-grid" id="calGrid" style="margin-top:8px"><div class="loading"><div class="spinner"></div></div></div>
        </div>
      </section>

      <!-- ================= پروژه‌ها ================= -->
      <section class="section" id="section-projects">
        <div class="toolbar">
          <h3 style="flex:1">لیست پروژه‌ها</h3>
          <?php if ($isManager): ?><button class="btn btn-primary btn-sm" id="btnNewProject">➕ پروژه جدید</button><?php endif; ?>
        </div>
        <div class="grid grid-3" id="projectsGrid"><div class="loading"><div class="spinner"></div></div></div>
      </section>

      <?php if ($isManager): ?>
      <!-- ================= کارمندان ================= -->
      <section class="section" id="section-employees">
        <div class="toolbar">
          <div class="search"><input type="text" id="empSearch" placeholder="جستجوی کارمند بر اساس نام، کد پرسنلی، سمت یا واحد..."><span>🔍</span></div>
          <button class="btn btn-primary btn-sm" id="btnNewEmployee">➕ معرفی کارمند جدید</button>
          <a class="btn btn-sm" href="export.php?type=employees&format=csv">📥 خروجی اکسل</a>
        </div>
        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr><th>کارمند</th><th>کد پرسنلی</th><th>سمت</th><th>واحد</th><th>نقش</th><th>تسک‌ها</th><th>وضعیت</th><th>آخرین ورود</th><th>عملیات</th></tr>
            </thead>
            <tbody id="employeesBody"><tr><td colspan="9"><div class="loading"><div class="spinner"></div></div></td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- ================= واحدها ================= -->
      <section class="section" id="section-departments">
        <div class="toolbar">
          <h3 style="flex:1">واحدهای سازمانی</h3>
          <button class="btn btn-primary btn-sm" id="btnNewDepartment">➕ واحد جدید</button>
        </div>
        <div class="grid grid-3" id="departmentsGrid"><div class="loading"><div class="spinner"></div></div></div>
      </section>
      <?php endif; ?>

      <!-- ================= حضور و غیاب ================= -->
      <section class="section" id="section-attendance">
        <div class="grid grid-3" style="margin-bottom:16px">
          <div class="card">
            <h3>⏰ حضور امروز</h3>
            <div class="att-summary">
              <div><div class="small muted">ساعت ورود</div><div class="att-big" id="attCheckIn">—</div></div>
              <div><div class="small muted">ساعت خروج</div><div class="att-big" id="attCheckOut">—</div></div>
            </div>
            <div class="small muted" id="attTotal" style="margin:8px 0">مدت حضور: —</div>
            <div class="toolbar" style="margin-bottom:0">
              <button class="btn btn-primary btn-sm" id="btnAttClockIn">ثبت ورود</button>
              <button class="btn btn-sm" id="btnAttClockOut">ثبت خروج</button>
            </div>
            <label class="field" style="margin-top:10px"><span>نوع کار امروز</span>
              <select id="attWorkMode"><option value="office">حضوری در شرکت</option><option value="remote">دورکاری</option><option value="mission">مأموریت</option></select>
            </label>
          </div>
          <div class="card">
            <h3>📊 خلاصه ماه</h3>
            <div class="att-summary">
              <div><div class="small muted">روزهای حضور</div><div class="att-big" id="attDaysPresent">—</div></div>
              <div><div class="small muted">مجموع کارکرد</div><div class="att-big" id="attMonthTotal">—</div></div>
            </div>
            <button class="btn btn-sm btn-block" style="margin-top:12px" id="btnAttExport">📥 خروجی اکسل حضور</button>
          </div>
          <div class="card">
            <h3>🔎 نمایش ماه</h3>
            <div class="form-row">
              <label class="field"><span>ماه</span><select id="attMonth"></select></label>
              <label class="field"><span>سال</span><input type="number" id="attYear" min="1390" max="1420"></label>
            </div>
            <?php if ($isManager): ?>
            <label class="field"><span>کارمند</span><select id="attUser"><option value="">خودم</option><?php foreach ($assignables as $a): ?><option value="<?= e((string) $a['id']) ?>"><?= e((string) $a['full_name']) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <button class="btn btn-sm btn-block" id="btnAttLoad">نمایش سوابق</button>
            <?php if ($isManager): ?>
            <button class="btn btn-sm btn-block" id="btnAttManual" style="margin-top:8px">📝 ثبت دستی حضور کارمند</button>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($isManager): ?>
        <div class="card" style="margin-bottom:16px">
          <h3>👥 حضور تیم امروز <span class="small muted" id="attTeamDate" style="font-weight:400"></span></h3>
          <div id="attTeamList"><div class="loading"><div class="spinner"></div></div></div>
        </div>
        <?php endif; ?>

        <div class="card">
          <h3>📅 سوابق حضور ماهانه</h3>
          <div class="table-wrap" style="border:0">
            <table class="tbl">
              <thead><tr><th>تاریخ شمسی</th><th>روز</th><th>ورود</th><th>خروج</th><th>مجموع</th><th>نوع کار</th></tr></thead>
              <tbody id="attendanceBody"><tr><td colspan="6" class="center muted">برای مشاهده، دکمه «نمایش سوابق» را بزنید.</td></tr></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ================= مرخصی‌ها ================= -->
      <section class="section" id="section-leaves">
        <div class="toolbar">
          <h3 style="flex:1">درخواست‌های مرخصی</h3>
          <button class="btn btn-primary btn-sm" id="btnNewLeave">🌴 درخواست مرخصی</button>
          <a class="btn btn-sm" href="export.php?type=leaves&format=csv">📥 خروجی اکسل</a>
        </div>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>کارمند</th><th>نوع</th><th>از تاریخ</th><th>تا تاریخ</th><th>ساعت</th><th>دلیل</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody id="leavesBody"><tr><td colspan="8"><div class="loading"><div class="spinner"></div></div></td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- ================= اطلاعیه‌ها ================= -->
      <section class="section" id="section-announcements">
        <div class="toolbar">
          <h3 style="flex:1">اطلاعیه‌های شرکت</h3>
          <?php if ($isManager): ?><button class="btn btn-primary btn-sm" id="btnNewAnnouncement">📢 اطلاعیه جدید</button><?php endif; ?>
        </div>
        <div id="announcementsList"><div class="loading"><div class="spinner"></div></div></div>
      </section>

      <!-- ================= اعلان‌ها ================= -->
      <section class="section" id="section-notifications">
        <div class="toolbar">
          <h3 style="flex:1">اعلان‌های من</h3>
          <button class="btn btn-sm" id="btnReadAll">✅ خواندن همه</button>
        </div>
        <div id="notificationsList"><div class="loading"><div class="spinner"></div></div></div>
      </section>

      <?php if ($isManager): ?>
      <!-- ================= گزارش فعالیت ================= -->
      <section class="section" id="section-activity">
        <div class="toolbar"><h3 style="flex:1">گزارش فعالیت‌های سیستم</h3><button class="btn btn-sm" id="btnReloadActivity">🔄 بازخوانی</button></div>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>کاربر</th><th>عملیات</th><th>توضیحات</th><th>زمان</th></tr></thead>
            <tbody id="activityBody"><tr><td colspan="4"><div class="loading"><div class="spinner"></div></div></td></tr></tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <!-- ================= پروفایل ================= -->
      <section class="section" id="section-profile">
        <div class="grid grid-2">
          <div class="card">
            <h3>👤 اطلاعات پروفایل</h3>
            <label class="field"><span>نام و نام خانوادگی</span><input type="text" id="pfFullName" value="<?= e((string) $user['full_name']) ?>"></label>
            <div class="form-row">
              <label class="field"><span>ایمیل</span><input type="email" id="pfEmail" value="<?= e((string) ($user['email'] ?? '')) ?>"></label>
              <label class="field"><span>تلفن</span><input type="text" id="pfPhone" value="<?= e((string) ($user['phone'] ?? '')) ?>"></label>
            </div>
            <label class="field"><span>سمت سازمانی</span><input type="text" id="pfPosition" value="<?= e($jobTitle) ?>"></label>
            <label class="field"><span>درباره من</span><textarea id="pfBio" rows="3"><?= e((string) ($user['bio'] ?? '')) ?></textarea></label>
            <button class="btn btn-primary" id="btnSaveProfile">💾 ذخیره تغییرات</button>
          </div>
          <div>
            <div class="card" style="margin-bottom:16px">
              <h3>🔑 تغییر رمز عبور</h3>
              <label class="field"><span>رمز عبور فعلی</span><input type="password" id="pwCurrent" autocomplete="current-password"></label>
              <div class="form-row">
                <label class="field"><span>رمز جدید</span><input type="password" id="pwNew" autocomplete="new-password"></label>
                <label class="field"><span>تکرار رمز جدید</span><input type="password" id="pwConfirm" autocomplete="new-password"></label>
              </div>
              <button class="btn btn-primary" id="btnChangePassword">🔒 تغییر رمز</button>
            </div>
            <div class="card">
              <h3>ℹ️ مشخصات حساب</h3>
              <dl class="kv">
                <dt>کد پرسنلی</dt><dd><?= e((string) $user['employee_code']) ?></dd>
                <dt>نام کاربری</dt><dd><?= e((string) $user['username']) ?></dd>
                <dt>نقش</dt><dd><?= e($roleLabel) ?></dd>
                <dt>واحد سازمانی</dt><dd><?= e($deptName) ?></dd>
                <dt>تاریخ استخدام</dt><dd><?= e(Jalali::toHuman($user['hire_date'] ?? null, true, true)) ?></dd>
                <dt>آخرین ورود</dt><dd><?= e($user['last_login_at'] ? Jalali::toDateTimeHuman((string) $user['last_login_at']) : '—') ?></dd>
              </dl>
            </div>
          </div>
        </div>
      </section>

      <?php if ($isAdmin): ?>
      <!-- ================= تنظیمات ================= -->
      <section class="section" id="section-settings">
        <div class="grid grid-2">
          <div class="card">
            <h3>🏢 تنظیمات شرکت</h3>
            <label class="field"><span>نام شرکت</span><input type="text" id="stCompanyName"></label>
            <label class="field"><span>شعار / توضیح</span><input type="text" id="stSlogan"></label>
            <div class="form-row">
              <label class="field"><span>ساعت شروع کار</span><input type="time" id="stWorkStart"></label>
              <label class="field"><span>ساعت پایان کار</span><input type="time" id="stWorkEnd"></label>
            </div>
            <h3 style="margin-top:16px">🎙️ تنظیمات هوش مصنوعی رایگان</h3>
            <label class="field"><span>سرویس پردازش صدا</span>
              <select id="stAiProvider">
                <option value="auto">🔀 خودکار رایگان (پیشنهادی)</option>
                <option value="groq">⚡ Groq رایگان — Whisper + Llama 3.3</option>
                <option value="gemini">✨ Google Gemini Flash رایگان</option>
                <option value="pollinations">☁️ Pollinations رایگان بدون کلید</option>
                <option value="local">🇮🇷 موتور هوشمند فارسی داخلی (آفلاین)</option>
              </select>
            </label>
            <label class="field"><span>کلید رایگان Groq (اختیاری)</span><input type="text" id="stGroqKey" dir="ltr" placeholder="gsk_..."></label>
            <label class="field"><span>کلید رایگان Gemini (اختیاری)</span><input type="text" id="stGeminiKey" dir="ltr" placeholder="AIza..."></label>
            <div class="small muted" style="margin:-4px 0 10px;line-height:1.9">
              🎧 تشخیص گفتار فارسی سه لایه دارد: کلید رایگان Groq (دقیق‌ترین) ← کلید Gemini ← Whisper رایگان بدون کلید (Pollinations).
              اگر هیچ‌کدام در دسترس نبود، دکمه «تبدیل صدا به متن بدون کلید» داخل پنجره ضبط، صدا را در مرورگر تبدیل می‌کند.
            </div>
            <div class="card" style="box-shadow:none;border:1px solid var(--border);margin-bottom:12px">
              <div class="toolbar" style="justify-content:space-between;margin-bottom:6px">
                <strong style="font-size:13px">🧪 وضعیت سرویس تشخیص گفتار</strong>
                <button type="button" class="btn btn-sm" id="btnAiCheck">بررسی دوباره</button>
              </div>
              <div id="aiStatusBox" class="small"><div class="loading"><div class="spinner"></div>در حال بررسی...</div></div>
            </div>
            <div class="checkline"><input type="checkbox" id="stSelfTask"><label for="stSelfTask">کارمندان بتوانند برای خودشان تسک ثبت کنند</label></div>
            <div class="checkline"><input type="checkbox" id="stPeerTask"><label for="stPeerTask">کارمندان بتوانند به همکاران تسک محول کنند</label></div>
            <button class="btn btn-primary" id="btnSaveSettings">💾 ذخیره تنظیمات</button>
          </div>
          <div class="card">
            <h3>🗄️ وضعیت پایگاه داده SQL</h3>
            <div id="dbStatusBox"><div class="loading"><div class="spinner"></div></div></div>
            <p class="small muted" style="margin-top:12px">جداول در اولین اتصال به صورت خودکار با پشتیبانی کامل از زبان فارسی ساخته می‌شوند. اگر جدولی حذف شود، هنگام درخواست بعدی دوباره ایجاد می‌شود.</p>
          </div>
        </div>
      </section>
      <?php endif; ?>
    </main>
  </div>
</div>

<!-- ================= مودال تسک ================= -->
<div class="modal-backdrop" id="modalTask">
  <div class="modal modal-lg">
    <div class="modal-head">
      <h3 id="taskModalTitle">➕ ثبت تسک جدید</h3>
      <button class="icon-btn" id="taskModalClose" title="بستن">✕</button>
    </div>
    <form id="taskForm">
      <input type="hidden" id="tfId" value="">
      <label class="field"><span>عنوان تسک *</span><input type="text" id="tfTitle" required placeholder="مثلاً: آماده‌سازی گزارش فروش ماهانه"></label>
      <label class="field"><span>توضیحات</span><textarea id="tfDescription" rows="3" placeholder="جزئیات تسک، اهداف و انتظارات..."></textarea></label>
      <div class="form-row3">
        <label class="field"><span>مسئول انجام *</span><select id="tfAssignee">
          <?php foreach ($assignables as $a): ?>
          <option value="<?= e((string) $a['id']) ?>" <?= (int) $a['id'] === $uid ? 'selected' : '' ?>><?= e((string) $a['full_name']) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label class="field"><span>پروژه</span><select id="tfProject">
          <option value="">بدون پروژه</option>
          <?php foreach ($projectsAll as $p): ?><option value="<?= e((string) $p['id']) ?>"><?= e((string) $p['name']) ?></option><?php endforeach; ?>
        </select></label>
        <label class="field"><span>وضعیت</span><select id="tfStatus">
          <option value="todo">در انتظار</option><option value="in_progress">در حال انجام</option>
          <option value="review">در بررسی</option><option value="done">انجام شده</option>
        </select></label>
      </div>
      <div class="form-row3">
        <label class="field"><span>اولویت</span><select id="tfPriority">
          <option value="low">کم</option><option value="medium" selected>متوسط</option>
          <option value="high">زیاد</option><option value="urgent">فوری</option>
        </select></label>
        <label class="field"><span>تاریخ سررسید (شمسی)</span><input type="text" id="tfDueDate" placeholder="۱۴۰۵/۰۸/۲۶" dir="ltr"></label>
        <label class="field"><span>ساعت سررسید</span><input type="time" id="tfDueTime"></label>
      </div>
      <div class="form-row3">
        <label class="field"><span>تاریخ شروع (شمسی)</span><input type="text" id="tfStartDate" placeholder="۱۴۰۵/۰۸/۲۰" dir="ltr"></label>
        <label class="field"><span>مدت تخمینی (دقیقه)</span><input type="number" id="tfEstimate" min="0" max="10080" value="60"></label>
        <label class="field"><span>تکرار</span><select id="tfRecurrence">
          <option value="none">بدون تکرار</option><option value="daily">روزانه</option>
          <option value="weekly">هفتگی</option><option value="monthly">ماهانه</option>
        </select></label>
      </div>
      <div class="form-row">
        <label class="field"><span>دسته‌بندی</span><input type="text" id="tfCategory" placeholder="مثلاً: فروش، فنی، اداری"></label>
        <label class="field"><span>برچسب‌ها (با کاما)</span><input type="text" id="tfTags" placeholder="گزارش، مشتری، فوری"></label>
      </div>
      <label class="field"><span>زیرتسک‌ها (هر خط یک مورد)</span><textarea id="tfSubtasks" rows="2" placeholder="جمع‌آوری داده‌ها&#10;تهیه نمودار&#10;ارسال به مدیر"></textarea></label>
      <div class="toolbar" style="margin-bottom:0">
        <button type="submit" class="btn btn-primary" id="btnSaveTask">💾 ذخیره تسک</button>
        <button type="button" class="btn" id="btnSuggestSubtasks">✨ پیشنهاد زیرتسک با هوش مصنوعی</button>
        <button type="button" class="btn" id="btnCancelTask">انصراف</button>
      </div>
    </form>
  </div>
</div>

<!-- ================= دراور جزئیات تسک ================= -->
<div class="drawer-backdrop" id="drawerBackdrop"></div>
<aside class="drawer" id="taskDrawer">
  <div class="drawer-head">
    <h3 id="drawerTitle">جزئیات تسک</h3>
    <button class="icon-btn" id="drawerClose" title="بستن">✕</button>
  </div>
  <div id="drawerBody"><div class="loading"><div class="spinner"></div></div></div>
</aside>

<!-- ================= مودال عمومی ================= -->
<div class="modal-backdrop" id="modalGeneric">
  <div class="modal" id="modalGenericBox">
    <div class="modal-head"><h3 id="genericTitle">—</h3><button class="icon-btn" id="genericClose">✕</button></div>
    <div id="genericBody"></div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script src="assets/js/jalali.js?v=<?= e(APP_VERSION) ?>"></script>
<script src="assets/js/voice-ai.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
window.APP = {
  csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
  user: <?= json_encode([
      'id' => $uid,
      'full_name' => $user['full_name'],
      'role' => $user['role'],
      'is_admin' => $isAdmin,
      'is_manager' => $isManager,
      'avatar_color' => $avatarColor,
      'initials' => $initials,
      'department_name' => $deptName,
      'position_title' => $jobTitle,
  ], JSON_UNESCAPED_UNICODE) ?>,
  users: <?= json_encode(array_map(fn($a) => ['id' => (int) $a['id'], 'full_name' => $a['full_name'], 'department_name' => $a['department_name'] ?? ''], $assignables), JSON_UNESCAPED_UNICODE) ?>,
  projects: <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $projectsAll), JSON_UNESCAPED_UNICODE) ?>,
  departments: <?= json_encode(array_map(fn($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $departments), JSON_UNESCAPED_UNICODE) ?>,
  today: <?= json_encode([
      'gregorian' => today_date(),
      'jalali' => Jalali::toDateString(today_date(), false),
      'jalali_fa' => $todayJalali,
      'human' => $todayHuman,
  ], JSON_UNESCAPED_UNICODE) ?>,
  companyName: <?= json_encode($companyName, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
