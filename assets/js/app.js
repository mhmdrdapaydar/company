/* همکار پلاس — اپلیکیشن اصلی پنل (فارسی / RTL / تقویم شمسی) */
(function () {
  'use strict';

  var J = window.JalaliJS;
  var FAT = J.toFa;
  var state = {
    section: 'dashboard',
    tasks: [],
    users: [],
    departments: [],
    projects: [],
    calendar: null,
    calYear: 0,
    calMonth: 0,
    tasksView: 'list',
    editingTask: null,
    currentTaskId: null,
    loaded: {},
    dashboard: null,
    unreadNotifs: 0
  };

  /* ---------------- ابزار پایه ---------------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /**
   * نمایش اعلان کوتاه. برای پیام‌های چندخطی و طولانی (مثل راهنمای خطای تشخیص گفتار)
   * پارامتر duration را زیاد کنید تا فرصت خواندن داشته باشد.
   */
  function toast(msg, type, duration) {
    if (!msg) return;
    var wrap = $('#toastWrap');
    var el = document.createElement('div');
    el.className = 'toast' + (type === 'error' ? ' error' : type === 'info' ? ' info' : '');
    el.style.whiteSpace = 'pre-line';
    el.innerHTML = (type === 'error' ? '⚠️ ' : type === 'info' ? 'ℹ️ ' : '✅ ') + esc(msg);
    wrap.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(function () { el.remove(); }, 320); }, duration || 4200);
  }

  // کنش‌های فقط‌خواندنی: با GET ارسال می‌شوند تا CSRF لازم نباشد.
  // قاعده مهم: هر کنشی که در این فهرست نباشد «نوشتنی» است و همیشه با POST می‌رود،
  // حتی اگر هیچ داده‌ای نداشته باشد (مثل «خواندن همه اعلان‌ها» یا «ثبت خروج»).
  var READ_ACTIONS = {
    me: 1, bootstrap: 1, dashboard_stats: 1, tasks_list: 1, task_get: 1, users_list: 1,
    departments_list: 1, projects_list: 1,
    announcements_list: 1, notifications_list: 1,
    activity_list: 1, calendar_month: 1, calendar_today: 1, assignable: 1, settings_get: 1, db_status: 1, ai_status: 1,
    notifications_unread: 1, live_reference: 1
  };

  /** درخواست به API — پشتیبانی از JSON و FormData */
  function api(action, payload, opts) {
    opts = opts || {};
    var isRead = !!READ_ACTIONS[action] && !opts.forcePost;
    var url = 'api.php?action=' + encodeURIComponent(action);
    var init = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (opts.form || payload instanceof FormData) {
      var fd = payload instanceof FormData ? payload : new FormData();
      fd.append('csrf_token', window.APP.csrf);
      init.method = 'POST';
      init.body = fd;
    } else if (!isRead) {
      // کنش نوشتنی: همیشه POST می‌شود؛ اگر داده‌ای نبود، فقط توکن امنیتی ارسال می‌شود
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = window.APP.csrf;
      init.body = JSON.stringify(Object.assign({ csrf_token: window.APP.csrf }, payload || {}));
    } else {
      var qs = new URLSearchParams();
      if (payload) Object.keys(payload).forEach(function (k) {
        if (payload[k] === null || payload[k] === undefined) return;
        qs.append(k, payload[k]);
      });
      url += '&' + qs.toString();
      init.method = 'GET';
    }
    return fetch(url, init).then(function (r) {
      return r.json().catch(function () { throw new Error('پاسخ نامعتبر از سرور دریافت شد.'); }).then(function (data) {
        if (!r.ok || data.ok === false) throw new Error(data.message || 'خطا در انجام عملیات.');
        return data;
      });
    });
  }

  function initials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '؟';
    if (parts.length === 1) return parts[0].slice(0, 2);
    return parts[0].charAt(0) + parts[parts.length - 1].charAt(0);
  }
  function avatar(name, color, extra) {
    return '<div class="avatar ' + (extra || '') + '" style="background:' + esc(color || '#0f766e') + '" title="' + esc(name) + '">' + esc(initials(name)) + '</div>';
  }
  function statusBadge(status, label) {
    var map = { todo: 'در انتظار', in_progress: 'در حال انجام', review: 'در بررسی', done: 'انجام شده' };
    return '<span class="badge badge-' + esc(status) + '">' + esc(label || map[status] || status) + '</span>';
  }
  function dueBadge(t) {
    var b = t.due_badge || { text: 'بدون سررسید', state: 'none' };
    return '<span class="badge badge-' + esc(b.state) + '">📅 ' + esc(b.text) + (t.due_time_fa ? ' — ' + esc(t.due_time_fa) : '') + '</span>';
  }

  function emptyState(icon, text, action) {
    return '<div class="empty"><span class="big">' + icon + '</span>' + esc(text) + (action ? '<div style="margin-top:12px">' + action + '</div>' : '') + '</div>';
  }

  /* ---------------- تم و ساعت ---------------- */
  function initTheme() {
    var cur = document.documentElement.getAttribute('data-theme') || 'light';
    $('#btnTheme').textContent = cur === 'dark' ? '☀️' : '🌙';
    $('#btnTheme').addEventListener('click', function () {
      var now = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', now);
      this.textContent = now === 'dark' ? '☀️' : '🌙';
      try { localStorage.setItem('hamkar_theme', now); } catch (e) {}
      rerenderCharts();
    });
  }
  function initClock() {
    var el = $('#liveClock');
    setInterval(function () {
      var d = new Date();
      el.textContent = FAT(String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'));
    }, 15000);
  }

  /* ---------------- ناوبری ---------------- */
  var TITLES = {
    dashboard: 'صفحه اصلی', reports: 'آمار و گزارش‌ها', tasks: 'مدیریت تسک‌ها', calendar: 'تقویم شمسی',
    employees: 'کارمندان',
    announcements: 'اطلاعیه‌ها',
    notifications: 'اعلان‌ها', activity: 'گزارش فعالیت‌ها', profile: 'پروفایل من', settings: 'تنظیمات سیستم'
  };

  function go(section, push) {
    if (!document.getElementById('section-' + section)) section = 'dashboard';
    state.section = section;
    $$('.section').forEach(function (s) { s.classList.toggle('active', s.id === 'section-' + section); });
    $$('#mainNav a[data-section]').forEach(function (a) { a.classList.toggle('active', a.getAttribute('data-section') === section); });
    $('#pageTitle').textContent = TITLES[section] || '';
    $('#sidebar').classList.remove('open');
    if (push !== false) {
      try { history.replaceState(null, '', '#' + section); } catch (e) {}
    }
    if (window.innerWidth <= 820) window.scrollTo(0, 0);
    loadSection(section);
  }

  function loadSection(section) {
    switch (section) {
      case 'reports': loadDashboard(); break;
      case 'tasks': loadTasks(); break;
      case 'calendar': loadCalendar(state.calYear, state.calMonth); break;
      case 'employees': loadEmployees(); break;
      case 'announcements': loadAnnouncements(); break;
      case 'notifications': loadNotifications(); break;
      case 'activity': loadActivity(); break;
      case 'settings': loadSettings(); break;
    }
  }

  function initNav() {
    $$('#mainNav a[data-section]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        go(this.getAttribute('data-section'));
      });
    });
    $('#menuToggle').addEventListener('click', function () { $('#sidebar').classList.toggle('open'); });
    document.addEventListener('click', function (e) {
      if (window.innerWidth <= 820 && !e.target.closest('#sidebar') && !e.target.closest('#menuToggle')) {
        $('#sidebar').classList.remove('open');
      }
    });
    var hash = (location.hash || '').replace('#', '');
    go(hash || 'dashboard', false);
  }

  /* ================= داشبورد ================= */
  function loadDashboard() {
    api('dashboard_stats').then(function (res) {
      state.dashboard = res;
      renderDashStats(res);
      renderMyTasks(res.my_tasks || []);
      renderUpcoming(res.my_tasks || []);
      renderChart7d(res.chart_7d || []);
    }).catch(function (err) { toast(err.message, 'error'); });
    // نمودار سررسیدهای نزدیک از تسک‌ها
    api('tasks_list', { scope: 'mine' }).then(function (res) {
      state.upcomingSource = res.tasks || [];
      renderUpcoming(state.dashboard ? state.dashboard.my_tasks || [] : res.tasks || []);
    }).catch(function () {});
  }

  function renderDashStats(d) {
    var cards = [
      { ico: '📋', bg: 'linear-gradient(135deg,#ccfbf1,#99f6e4)', color: '#0f766e', num: d.total, label: 'کل تسک‌ها', sub: 'نرخ تکمیل ' + FAT(d.completion_rate) + '٪' },
      { ico: '🔵', bg: 'linear-gradient(135deg,#dbeafe,#bfdbfe)', color: '#1d4ed8', num: d.by_status.in_progress, label: 'در حال انجام', sub: 'در انتظار: ' + FAT(d.by_status.todo) },
      { ico: '⏰', bg: 'linear-gradient(135deg,#fee2e2,#fecaca)', color: '#b91c1c', num: d.overdue, label: 'عقب‌افتاده', sub: 'سررسید امروز: ' + FAT(d.due_today) },
      { ico: '✅', bg: 'linear-gradient(135deg,#d1fae5,#a7f3d0)', color: '#047857', num: d.done_week, label: 'تکمیل در ۷ روز', sub: 'انجام‌شده کل: ' + FAT(d.by_status.done) }
    ];
    $('#dashStats').innerHTML = cards.map(function (c) {
      return '<div class="card stat-card">' +
        '<div class="stat-ico" style="background:' + c.bg + ';color:' + c.color + '">' + c.ico + '</div>' +
        '<div><div class="stat-num">' + FAT(c.num) + '</div><div class="stat-label">' + c.label + '</div>' +
        '<div class="small muted">' + c.sub + '</div></div></div>';
    }).join('') +
      (APP.user.is_manager ?
        '<div class="card"><h3>🏢 وضعیت شرکت</h3>' +
        '<div class="small">کارمندان فعال: <strong>' + FAT(d.team_count) + '</strong></div>' +
        '</div>' : '');
    var box = $('#dashStats');
    if (APP.user.is_manager) {
      box.insertAdjacentHTML('beforeend', '');
    }
  }

  function renderMyTasks(tasks) {
    var wrap = $('#dashMyTasks');
    if (!tasks.length) { wrap.innerHTML = emptyState('🎉', 'تسکی روی میز شما نیست!'); return; }
    wrap.innerHTML = tasks.slice(0, 6).map(function (t) {
      return '<div class="task-list-row" data-task="' + t.id + '">' +
        '<div class="grow"><h5>' + esc(t.title) + '</h5>' +
        '<div class="small muted">' + esc(t.task_code) + ' • ' + statusBadge(t.status, t.status_label) + '</div></div>' +
        dueBadge(t) + '</div>';
    }).join('');
    $$('#dashMyTasks .task-list-row').forEach(function (row) {
      row.addEventListener('click', function () { openTask(Number(this.getAttribute('data-task'))); });
    });
  }

  function renderUpcoming() {
    var wrap = $('#dashUpcoming');
    var src = (state.upcomingSource || state.tasks || []).filter(function (t) { return t.status !== 'done' && t.due_date; });
    src.sort(function (a, b) { return String(a.due_date).localeCompare(String(b.due_date)); });
    if (!src.length) { wrap.innerHTML = emptyState('🗓️', 'سررسیدی ثبت نشده است.'); return; }
    wrap.innerHTML = src.slice(0, 6).map(function (t) {
      return '<div class="task-list-row" data-task="' + t.id + '">' +
        '<div class="grow"><h5>' + esc(t.title) + '</h5>' +
        '<div class="small muted">👤 ' + esc(t.assignee_name || '—') + '</div></div>' +
        dueBadge(t) + '</div>';
    }).join('');
    $$('#dashUpcoming .task-list-row').forEach(function (row) {
      row.addEventListener('click', function () { openTask(Number(this.getAttribute('data-task'))); });
    });
  }

  /* ---------------- نمودارها (Canvas خالص) ---------------- */
  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }
  function prepCanvas(canvas) {
    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    var w = Math.max(rect.width, 240), h = Math.max(rect.height, 140);
    canvas.width = w * dpr; canvas.height = h * dpr;
    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx: ctx, w: w, h: h };
  }
  function renderChart7d(data) {
    var canvas = $('#chart7d');
    if (!canvas) return;
    var p = prepCanvas(canvas), ctx = p.ctx, w = p.w, h = p.h;
    var text = cssVar('--text', '#0f172a'), muted = cssVar('--muted', '#64748b'), border = cssVar('--border', '#e2e8f0');
    ctx.clearRect(0, 0, w, h);
    var padR = 34, padL = 10, padT = 16, padB = 32;
    var cw = w - padR - padL, ch = h - padT - padB;
    var n = data.length || 1;
    var max = Math.max(2, Math.max.apply(null, data.map(function (d) { return Math.max(d.created, d.completed); })));
    // خطوط افقی
    ctx.strokeStyle = border; ctx.lineWidth = 1;
    for (var g = 0; g <= 4; g++) {
      var y = padT + ch - (ch * g / 4);
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(padL + cw, y); ctx.stroke();
      ctx.fillStyle = muted; ctx.font = '11px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
      ctx.fillText(FAT(Math.round(max * g / 4)), padL + cw + 6, y + 4);
    }
    var groupW = cw / n, barW = Math.min(18, groupW / 3.2);
    data.forEach(function (d, i) {
      var gx = padL + groupW * i + groupW / 2;
      var hc = (d.created / max) * ch, hd = (d.completed / max) * ch;
      var grad1 = ctx.createLinearGradient(0, padT + ch - hc, 0, padT + ch);
      grad1.addColorStop(0, '#0d9488'); grad1.addColorStop(1, '#5eead4');
      roundRect(ctx, gx - barW - 2, padT + ch - hc, barW, hc, 4); ctx.fillStyle = grad1; ctx.fill();
      var grad2 = ctx.createLinearGradient(0, padT + ch - hd, 0, padT + ch);
      grad2.addColorStop(0, '#0284c7'); grad2.addColorStop(1, '#7dd3fc');
      roundRect(ctx, gx + 2, padT + ch - hd, barW, hd, 4); ctx.fillStyle = grad2; ctx.fill();
      ctx.fillStyle = text; ctx.font = '11px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
      ctx.fillText(d.label, gx, h - 10);
    });
    // راهنما
    ctx.textAlign = 'right';
    ctx.fillStyle = '#0d9488'; ctx.fillRect(w - 130, 6, 10, 10);
    ctx.fillStyle = text; ctx.fillText('ایجاد شده', w - 140, 15);
    ctx.fillStyle = '#0284c7'; ctx.fillRect(w - 70, 6, 10, 10);
    ctx.fillStyle = text; ctx.fillText('تکمیل', w - 80, 15);
  }
  function rerenderCharts() {
    if (state.section === 'reports' && state.dashboard) {
      renderChart7d(state.dashboard.chart_7d || []);
    }
  }

  /* ================= تسک‌ها ================= */
  function taskQuery() {
    var q = { scope: $('#taskScope').value };
    var s = $('#taskSearch').value.trim();
    if (s) q.search = s;
    var st = $('#taskStatusFilter').value; if (st) q.status = st;
    var uf = $('#taskUserFilter'); if (uf && uf.value) q.assigned_to = uf.value;
    return q;
  }

  var searchTimer = null;
  var taskRequestVersion = 0;
  function loadTasks(silent) {
    silent = silent === true;
    var version = ++taskRequestVersion;
    var query = taskQuery();
    var queryKey = JSON.stringify(query);
    if (!silent) $('#tasksListView').innerHTML = '<div class="loading"><div class="spinner"></div>در حال دریافت تسک‌ها...</div>';
    api('tasks_list', query).then(function (res) {
      if (version !== taskRequestVersion || queryKey !== JSON.stringify(taskQuery())) return;
      if (silent && (!canRefreshLive() || state.section !== 'tasks')) return;
      state.tasks = res.tasks || [];
      renderTasks();
    }).catch(function (err) {
      if (silent || version !== taskRequestVersion || queryKey !== JSON.stringify(taskQuery())) return;
      $('#tasksListView').innerHTML = emptyState('⚠️', err.message);
      toast(err.message, 'error');
    });
  }

  function renderTasks() {
    if (state.tasksView === 'kanban') {
      $('#tasksListView').classList.add('hidden');
      $('#tasksKanbanView').classList.remove('hidden');
      renderKanban();
      return;
    }
    $('#tasksKanbanView').classList.add('hidden');
    $('#tasksListView').classList.remove('hidden');
    if (!state.tasks.length) {
      $('#tasksListView').innerHTML = emptyState('🗂️', 'تسکی با این فیلترها پیدا نشد.', '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'btnNewTask\').click()">➕ ثبت اولین تسک</button>');
      return;
    }
    var html = state.tasks.map(function (t) {
      return '<div class="task-list-row" data-task="' + t.id + '">' +
        '<div class="grow">' +
        '<h5>' + esc(t.title) + (t.created_via === 'voice_ai' ? ' <span title="ثبت با هوش مصنوعی">🎙️</span>' : '') + '</h5>' +
        '<div class="small muted">' + esc(t.task_code) + ' • ' + esc(t.assignee_name || '—') +
        '</div></div>' +
        '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:flex-end">' +
        statusBadge(t.status) + dueBadge(t) + '</div>' +
        (t.progress > 0 && t.status !== 'done' ?
          '<div style="width:70px"><div class="progress"><div style="width:' + t.progress + '%"></div></div><div class="small muted center">' + FAT(t.progress) + '٪</div></div>' : '') +
        '</div>';
    }).join('');
    $('#tasksListView').innerHTML = html;
    $$('#tasksListView .task-list-row').forEach(function (row) {
      row.addEventListener('click', function () { openTask(Number(this.getAttribute('data-task'))); });
    });
  }

  var COLS = [
    { key: 'todo', title: '📌 در انتظار', cls: 'todo' },
    { key: 'in_progress', title: '🔄 در حال انجام', cls: 'in_progress' },
    { key: 'review', title: '👀 در بررسی', cls: 'review' },
    { key: 'done', title: '✅ انجام شده', cls: 'done' }
  ];
  function renderKanban() {
    var board = $('#kanbanBoard');
    board.innerHTML = COLS.map(function (c) {
      var items = state.tasks.filter(function (t) { return t.status === c.key; });
      return '<div class="kanban-col" data-status="' + c.key + '">' +
        '<h4>' + c.title + ' <span class="count">' + FAT(items.length) + '</span></h4>' +
        (items.length ? items.map(kanbanCard).join('') : '<div class="small muted center" style="padding:14px">خالی</div>') +
        '</div>';
    }).join('');
    $$('#kanbanBoard .task-card').forEach(function (card) {
      card.addEventListener('click', function () { openTask(Number(this.getAttribute('data-task'))); });
      card.setAttribute('draggable', 'true');
      card.addEventListener('dragstart', function (e) {
        e.dataTransfer.setData('text/plain', this.getAttribute('data-task'));
        this.style.opacity = '.4';
      });
      card.addEventListener('dragend', function () { this.style.opacity = ''; });
    });
    $$('#kanbanBoard .kanban-col').forEach(function (col) {
      col.addEventListener('dragover', function (e) { e.preventDefault(); this.classList.add('drag-over'); });
      col.addEventListener('dragleave', function () { this.classList.remove('drag-over'); });
      col.addEventListener('drop', function (e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        var id = Number(e.dataTransfer.getData('text/plain'));
        if (!id) return;
        var task = state.tasks.filter(function (t) { return t.id === id; })[0];
        var newStatus = this.getAttribute('data-status');
        if (!task || task.status === newStatus) return;
        api('task_update', { id: id, status: newStatus }).then(function (res) {
          task.status = newStatus;
          renderKanban();
          toast('وضعیت تسک به «' + (COLS.filter(function (c) { return c.key === newStatus; })[0] || {}).title.replace(/^[^\s]+\s/, '') + '» تغییر کرد ✅');
          if (state.section === 'reports') loadDashboard();
        }).catch(function (err) { toast(err.message, 'error'); renderKanban(); });
      });
    });
  }
  function kanbanCard(t) {
    return '<div class="task-card" data-task="' + t.id + '">' +
      '<h5>' + esc(t.title) + (t.created_via === 'voice_ai' ? ' 🎙️' : '') + '</h5>' +
      '<div class="tc-meta">' + dueBadge(t) + '</div>' +
      (t.progress > 0 ? '<div class="progress" style="margin-top:8px"><div style="width:' + t.progress + '%"></div></div>' : '') +
      '<div class="tc-foot">' + avatar(t.assignee_name, t.assignee_color, 'avatar-sm') + '<span class="small muted">' + esc(t.assignee_name || '—') + '</span></div>' +
      '</div>';
  }

  function initTaskToolbar() {
    $('#taskSearch').addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(loadTasks, 380);
    });
    ['#taskScope', '#taskStatusFilter', '#taskUserFilter'].forEach(function (s) {
      var el = $(s);
      if (el) el.addEventListener('change', loadTasks);
    });
    $$('.view-toggle button').forEach(function (b) {
      b.addEventListener('click', function () {
        $$('.view-toggle button').forEach(function (x) { x.classList.remove('active'); });
        this.classList.add('active');
        state.tasksView = this.getAttribute('data-view');
        renderTasks();
      });
    });
    $('#btnNewTask').addEventListener('click', function () { openTaskForm(null); });
  }

  /* ---------------- فرم تسک ---------------- */
  function openTaskForm(task, prefill) {
    state.editingTask = task || null;
    $('#taskModalTitle').textContent = task ? '✏️ ویرایش تسک ' + (task.task_code || '') : '➕ ثبت تسک جدید';
    $('#tfId').value = task ? task.id : '';
    $('#tfTitle').value = task ? task.title : (prefill && prefill.title) || '';
    $('#tfDescription').value = task ? (task.description || '') : (prefill && prefill.description) || '';
    $('#tfAssignee').value = String(task ? task.assigned_to : (prefill && prefill.assigned_to) || APP.user.id);
    var multi = $('#tfMultiWrap'); if (multi) { multi.hidden = !!task; $('#tfAll').checked = false; Array.from($('#tfAssignees').options).forEach(function (o) { o.selected = false; }); }
    $('#tfStatus').value = task ? task.status : 'todo';
    $('#tfDueDate').value = task && task.due_date ? J.gregorianToJalaliInput(task.due_date) : (prefill && prefill.due_date_jalali) || APP.today.jalali;
    $('#tfDueTime').value = task && task.due_time ? task.due_time : (prefill && prefill.due_time) || '';
    $('#tfStartDate').value = task && task.start_date ? J.gregorianToJalaliInput(task.start_date) : '';
    $('#tfRecurrence').value = task ? (task.recurrence || 'none') : 'none';
    $('#modalTask').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { $('#tfTitle').focus(); }, 120);
  }
  function closeTaskForm() {
    $('#modalTask').classList.remove('open');
    document.body.style.overflow = '';
    state.editingTask = null;
  }
  function initTaskForm() {
    $('#taskModalClose').addEventListener('click', closeTaskForm);
    $('#btnCancelTask').addEventListener('click', closeTaskForm);
    $('#modalTask').addEventListener('click', function (e) { if (e.target === this) closeTaskForm(); });
    $('#taskForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var id = $('#tfId').value;
      var dueJ = $('#tfDueDate').value.trim();
      var dueG = dueJ ? J.parseToGregorian(dueJ) : '';
      if (dueJ && !dueG) { toast('تاریخ سررسید شمسی نامعتبر است (نمونه: ۱۴۰۵/۰۸/۲۶).', 'error'); return; }
      var startJ = $('#tfStartDate').value.trim();
      var startG = startJ ? J.parseToGregorian(startJ) : null;
      if (startJ && !startG) { toast('تاریخ شروع شمسی نامعتبر است.', 'error'); return; }
      var payload = {
        title: $('#tfTitle').value.trim(),
        description: $('#tfDescription').value.trim(),
        assigned_to: Number($('#tfAssignee').value),
        status: $('#tfStatus').value,
        due_date: dueG || '',
        due_time: $('#tfDueTime').value,
        start_date: startG || '',
        recurrence: $('#tfRecurrence').value
      };
      if (!id && $('#tfMultiWrap')) { var selected = Array.from($('#tfAssignees').selectedOptions).map(function (o) { return Number(o.value); }); if ($('#tfAll').checked) payload.assign_all = true; else if (selected.length) payload.assigned_ids = selected; }
      if (!payload.title) { toast('عنوان تسک الزامی است.', 'error'); return; }
      var btn = $('#btnSaveTask');
      btn.disabled = true;
      var promise = id ? api('task_update', Object.assign({ id: Number(id) }, payload)) : api('task_create', payload);
      promise.then(function (res) {
        toast(res.message || 'تسک ذخیره شد.');
        closeTaskForm();
        if (state.section === 'tasks') loadTasks();
        if (state.section === 'calendar') loadCalendar(state.calYear, state.calMonth);
        if (state.section === 'reports') loadDashboard();
        if (res.task && state.currentTaskId === res.task.id) openTask(res.task.id);
      }).catch(function (err) { toast(err.message, 'error'); })
        .finally(function () { btn.disabled = false; });
    });
  }

  /* ---------------- دراور جزئیات ---------------- */
  function openTask(id) {
    state.currentTaskId = id;
    $('#drawerBackdrop').classList.add('open');
    $('#taskDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
    $('#drawerTitle').textContent = 'در حال بارگذاری...';
    $('#drawerBody').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    renderTaskDrawer(id);
  }
  function closeDrawer() {
    $('#drawerBackdrop').classList.remove('open');
    $('#taskDrawer').classList.remove('open');
    document.body.style.overflow = '';
    state.currentTaskId = null;
  }
  function renderTaskDrawer(id) {
    api('task_get', { id: id }).then(function (res) {
      var t = res.task;
      $('#drawerTitle').innerHTML = esc(t.title);
      var html = '' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">' +
        statusBadge(t.status, t.status_label) + dueBadge(t) +
        (t.created_via === 'voice_ai' ? '<span class="badge badge-low">🎙️ ساخته‌شده با هوش مصنوعی</span>' : '') +
        '</div>' +
        '<div class="small muted" style="margin-bottom:10px">کد: ' + esc(t.task_code) + ' • سازنده: ' + esc(t.creator_name || '—') + ' • ایجاد: ' + esc(t.created_human) + '</div>' +
        '<div class="progress" style="margin-bottom:6px"><div style="width:' + (t.progress || 0) + '%"></div></div>' +
        '<div class="small muted center" style="margin-bottom:12px">پیشرفت ' + FAT(t.progress || 0) + '٪</div>' +
        (t.description ? '<div class="card" style="padding:12px;margin-bottom:12px;font-size:13px;white-space:pre-wrap">' + esc(t.description) + '</div>' : '') +
        '<div class="toolbar">' +
        '<button class="btn btn-sm btn-primary" data-act="edit">✏️ ویرایش</button>' +
        (t.running_timer ? '<button class="btn btn-sm btn-danger" data-act="timer-stop">⏹️ توقف تایمر</button>' : '<button class="btn btn-sm" data-act="timer-start">⏱️ شروع تایمر</button>') +
        '<button class="btn btn-sm" data-act="advance">➡️ مرحله بعد</button>' +
        '<button class="btn btn-sm" data-act="archive">' + (t.is_archived ? '♻️ بازگردانی' : '🗄️ بایگانی') + '</button>' +
        '<button class="btn btn-sm btn-danger" data-act="delete">🗑️ حذف</button>' +
        '</div>' +
        '<hr style="border:0;border-top:1px solid var(--border);margin:14px 0">' +
        '<h4 style="font-size:14px">📎 پیوست‌ها (' + FAT(t.attachments.length) + ')</h4>' +
        '<div id="drawerAttachments">' + (t.attachments.length ? t.attachments.map(function (a) {
          return '<div class="attach-item"><span>📄</span><span class="grow" title="' + esc(a.original_name) + '">' + esc(a.original_name) + '</span><span class="small muted">' + esc(a.size_fa) + '</span><a class="btn btn-sm" href="' + esc(a.download_url) + '">⬇️</a><button class="icon-btn" data-attdel="' + a.id + '">✕</button></div>';
        }).join('') : '<div class="small muted">پیوستی ندارد.</div>') + '</div>' +
        '<label class="btn btn-sm" style="margin-top:8px;cursor:pointer">📤 افزودن پیوست<input type="file" id="attachInput" class="hidden"></label>' +
        (t.voice_url ? '<div style="margin-top:10px"><div class="small muted">🎙️ پیام صوتی این تسک:</div><audio class="voice-player" controls src="' + esc(t.voice_url) + '"></audio>' +
          (t.voice_transcript ? '<div class="transcript-box small" style="margin-top:6px">' + esc(t.voice_transcript) + '</div>' : '') + '</div>' : '') +
        '<hr style="border:0;border-top:1px solid var(--border);margin:14px 0">' +
        '<h4 style="font-size:14px">💬 نظرات (' + FAT(t.comments.length) + ')</h4>' +
        '<div id="drawerComments">' + (t.comments.length ? t.comments.map(function (c) {
          return '<div class="comment">' + avatar(c.user_name, c.avatar_color, 'avatar-sm') + '<div class="body"><div class="meta"><strong>' + esc(c.user_name) + '</strong> • ' + esc(c.created_ago) + '</div><div style="font-size:13px;white-space:pre-wrap">' + esc(c.body) + '</div>' +
            (c.voice_url ? '<audio class="voice-player" controls src="' + esc(c.voice_url) + '"></audio>' : '') + '</div></div>';
        }).join('') : '<div class="small muted">هنوز نظری ثبت نشده.</div>') + '</div>' +
        '<label class="field" style="margin-top:8px"><span>نظر جدید (متن یا صدا)</span><textarea id="commentInput" rows="2" placeholder="نظر خود را بنویسید..."></textarea></label>' +
        '<div class="toolbar">' +
        '<button class="btn btn-sm btn-primary" data-act="comment-send">💬 ارسال نظر</button>' +
        '<button class="btn btn-sm" data-act="comment-voice">🎙️ ضبط نظر صوتی</button>' +
        '</div>' +
        (t.total_logged_minutes > 0 ? '<div class="small muted" style="margin-top:10px">⏱️ مجموع زمان ثبت‌شده: ' + esc(t.total_logged_fa) + '</div>' : '');

      $('#drawerBody').innerHTML = html;
      bindDrawer(t);
    }).catch(function (err) {
      $('#drawerBody').innerHTML = emptyState('⚠️', err.message);
    });
  }

  function bindDrawer(t) {
    var body = $('#drawerBody');
    body.querySelectorAll('[data-act]').forEach(function (b) {
      b.addEventListener('click', function () {
        var act = this.getAttribute('data-act');
        if (act === 'edit') {
          closeDrawer();
          api('task_get', { id: t.id }).then(function (res) { openTaskForm(res.task); });
        } else if (act === 'timer-start') {
          api('timer_start', { task_id: t.id }).then(function (r) { toast(r.message || 'تایمر شروع شد.'); renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'timer-stop') {
          api('timer_stop', { task_id: t.id }).then(function (r) { toast(r.message || 'تایمر متوقف شد.'); renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'advance') {
          var order = ['todo', 'in_progress', 'review', 'done'];
          var next = order[(order.indexOf(t.status) + 1) % order.length];
          api('task_update', { id: t.id, status: next }).then(function (r) {
            toast('وضعیت: ' + (r.task ? r.task.status_label : next));
            renderTaskDrawer(t.id);
            if (state.section === 'tasks') loadTasks();
            if (state.section === 'reports') loadDashboard();
          }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'archive') {
          api('task_update', { id: t.id, is_archived: t.is_archived ? 0 : 1 }).then(function () {
            toast(t.is_archived ? 'تسک بازگردانی شد.' : 'تسک بایگانی شد.');
            closeDrawer();
            if (state.section === 'tasks') loadTasks();
            if (state.section === 'reports') loadDashboard();
          }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'delete') {
          if (!confirm('آیا از حذف این تسک مطمئن هستید؟ این عملیات قابل بازگشت نیست.')) return;
          api('task_delete', { id: t.id }).then(function (r) {
            toast(r.message || 'تسک حذف شد.');
            closeDrawer();
            if (state.section === 'tasks') loadTasks();
            if (state.section === 'reports') loadDashboard();
            if (state.section === 'calendar') loadCalendar(state.calYear, state.calMonth);
          }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'sub-add') {
          var input = $('#newSubtaskInput');
          var title = input.value.trim();
          if (!title) return;
          api('subtask_add', { task_id: t.id, title: title }).then(function () { input.value = ''; renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'comment-send') {
          var txt = $('#commentInput').value.trim();
          if (!txt) { toast('متن نظر را بنویسید.', 'error'); return; }
          api('comment_add', { task_id: t.id, body: txt }).then(function () { toast('نظر ثبت شد.'); renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'comment-voice') {
          recordVoiceComment(t.id);
        }
      });
    });
    body.querySelectorAll('[data-sub]').forEach(function (chk) {
      chk.addEventListener('change', function () {
        api('subtask_toggle', { id: Number(this.getAttribute('data-sub')) }).then(function () { renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
      });
    });
    body.querySelectorAll('[data-subdel]').forEach(function (b) {
      b.addEventListener('click', function () {
        api('subtask_delete', { id: Number(this.getAttribute('data-subdel')) }).then(function () { renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
      });
    });
    body.querySelectorAll('[data-attdel]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('پیوست حذف شود؟')) return;
        api('attachment_delete', { id: Number(this.getAttribute('data-attdel')) }).then(function () { renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
      });
    });
    var attachInput = $('#attachInput');
    if (attachInput) {
      attachInput.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        var fd = new FormData();
        fd.append('task_id', t.id);
        fd.append('file', file);
        toast('در حال آپلود فایل...', 'info');
        api('attachment_upload', fd, { form: true }).then(function (r) { toast(r.message || 'فایل پیوست شد.'); renderTaskDrawer(t.id); }).catch(function (e) { toast(e.message, 'error'); });
      });
    }
  }

  /* ضبط نظر صوتی داخل تسک */
  function recordVoiceComment(taskId) {
    if (!navigator.mediaDevices || !window.MediaRecorder) { toast('ضبط صدا در این مرورگر پشتیبانی نمی‌شود.', 'error'); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var chunks = [];
      var rec = new MediaRecorder(stream);
      rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
      rec.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        var blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
        var fd = new FormData();
        fd.append('task_id', taskId);
        fd.append('body', '🎙️ پیام صوتی');
        fd.append('voice', blob, 'comment.' + (blob.type.indexOf('mp4') >= 0 ? 'm4a' : 'webm'));
        api('comment_add', fd, { form: true }).then(function () { toast('پیام صوتی ثبت شد.'); renderTaskDrawer(taskId); }).catch(function (e) { toast(e.message, 'error'); });
      };
      rec.start();
      toast('🔴 ضبط پیام صوتی شروع شد. برای پایان، دوباره روی دکمه بزنید.', 'info');
      var stopBtn = document.querySelector('[data-act="comment-voice"]');
      if (stopBtn) {
        stopBtn.textContent = '⏹️ پایان ضبط';
        stopBtn.addEventListener('click', function handler() {
          stopBtn.removeEventListener('click', handler);
          try { rec.stop(); } catch (e) {}
        });
      }
      setTimeout(function () { try { if (rec.state !== 'inactive') rec.stop(); } catch (e) {} }, 60000);
    }).catch(function () { toast('دسترسی به میکروفن امکان‌پذیر نیست.', 'error'); });
  }

  function initDrawer() {
    $('#drawerClose').addEventListener('click', closeDrawer);
    $('#drawerBackdrop').addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeDrawer(); closeTaskForm(); } });
  }

  /* ================= تقویم ================= */
  var todayRequestVersion = 0;
  function loadCalendarToday(silent) {
    var version = ++todayRequestVersion;
    if (!silent) $('#calTodayTasks').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    api('calendar_today').then(function (res) {
      if (version !== todayRequestVersion || state.section !== 'calendar') return;
      var tasks = Array.isArray(res.tasks) ? res.tasks : [];
      $('#calTodayTasks').innerHTML = '<div class="small muted cal-today-date">' + esc(res.date_human || '') + '</div>' +
        (tasks.length ? tasks.map(function (t) {
          return '<button type="button" class="cal-today-task" data-task="' + Number(t.id) + '"><span class="cal-today-title">' + esc(t.title) + '</span>' +
            '<span class="cal-today-meta">' + (t.due_time_fa ? '🕐 ' + esc(t.due_time_fa) + ' · ' : '') + esc(t.assignee_name || '') + ' · ' + esc(t.status_label || '') + '</span></button>';
        }).join('') : '<p class="small muted">امروز تسکی وجود ندارد.</p>');
      $$('#calTodayTasks [data-task]').forEach(function (el) {
        el.addEventListener('click', function () { openTask(Number(this.getAttribute('data-task'))); });
      });
    }).catch(function () {
      if (!silent && version === todayRequestVersion && state.section === 'calendar') $('#calTodayTasks').innerHTML = '<p class="small muted">دریافت تسک‌های امروز ممکن نشد.</p>';
    });
  }
  function loadCalendar(jy, jm, silent) {
    var t = J.todayJalali();
    jy = jy || state.calYear || t[0];
    jm = jm || state.calMonth || t[1];
    state.calYear = jy; state.calMonth = jm;
    loadCalendarToday(silent);
    if (!silent) {
      $('#calGrid').innerHTML = '<div class="loading" style="grid-column:1/-1"><div class="spinner"></div></div>';
      $('#calAgenda').innerHTML = '';
    }
    api('calendar_month', { jy: jy, jm: jm }).then(function (res) {
      if (jy !== state.calYear || jm !== state.calMonth || (silent && (!canRefreshLive() || state.section !== 'calendar'))) return;
      state.calendar = res.calendar;
      renderCalendar(res.calendar);
    }).catch(function (err) {
      if (silent || jy !== state.calYear || jm !== state.calMonth) return;
      $('#calGrid').innerHTML = emptyState('⚠️', err.message);
      $('#calAgenda').innerHTML = '';
    });
  }
  function renderCalendar(cal) {
    $('#calTitle').textContent = cal.title + ' — ' + (cal.season || '');
    // ⚠️ سرستون‌ها و روزها در «یک» گرید رندر می‌شوند تا ستون‌ها هرگز ناهم‌تراز نشوند
    // (قبلاً دو گرید جدا بودند و با اسکرول یا تفاوت اندازه سلول‌ها جابه‌جا می‌شدند)
    var cells = J.WEEKDAY_NAMES.map(function (w, i) {
      return '<div class="cal-weekday' + (i === 6 ? ' fri' : '') + '" role="columnheader">' + w + '</div>';
    });
    var startIdx = ((Number(cal.start_weekday) % 7) + 7) % 7;
    // ۷ خانه اول را نام روزها پر کرده‌اند؛ برای اینکه روز اول دقیقاً زیر نام روز خودش بنشیند
    // باید به تعداد startIdx خانه خالی اضافه کنیم (نه 7-startIdx).
    var leading = startIdx;
    for (var i = 0; i < leading; i++) cells.push('<div class="cal-day empty" aria-hidden="true"></div>');
    cal.days.forEach(function (d) {
      var tasks = d.tasks || [];
      cells.push('<div class="cal-day' + (d.is_today ? ' today' : '') + (d.is_friday ? ' friday' : '') + '" data-date="' + d.gregorian + '" data-jalali="' + d.jalali + '">' +
        '<div class="dnum">' + d.day_fa + (d.is_today ? '<span class="today-tag">امروز</span>' : '') + '</div>' +
        tasks.slice(0, 3).map(function (t) {
          return '<span class="cal-task st-' + esc(t.status) + '" data-task="' + t.id + '" title="' + esc(t.title + ' — ' + t.assignee_name) + '" style="background:' + hexAlpha(t.project_color, .14) + '">' +
            (t.due_time_fa ? '🕐 ' + esc(t.due_time_fa) + ' ' : '') + esc(t.title) + '</span>';
        }).join('') +
        (tasks.length ? '<span class="cal-count" aria-label="' + FAT(tasks.length) + ' تسک">' + FAT(tasks.length) + '</span>' : '') +
        (tasks.length > 3 ? '<div class="cal-more">+' + FAT(tasks.length - 3) + ' تسک دیگر</div>' : '') +
        '</div>');
    });
    var total = cal.days.length + leading;
    var pad = (7 - (total % 7)) % 7;
    for (var k = 0; k < pad; k++) cells.push('<div class="cal-day empty" aria-hidden="true"></div>');
    $('#calGrid').innerHTML = cells.join('');
    // A full-width agenda keeps every task legible on narrow screens, including items beyond the first three.
    var agenda = $('#calAgenda');
    var daysWithTasks = cal.days.filter(function (d) { return (d.tasks || []).length; });
    agenda.innerHTML = '<h4>تسک‌های این ماه</h4>' + (daysWithTasks.length ? daysWithTasks.map(function (d) {
      return '<div class="cal-agenda-day"><div class="cal-agenda-date">' + esc(d.day_fa) + ' ' + esc(cal.title) + '</div>' + d.tasks.map(function (t) {
        return '<button type="button" class="cal-agenda-task" data-task="' + Number(t.id) + '">' +
          '<span>' + esc(t.title) + '</span>' + (t.due_time_fa ? '<span class="cal-agenda-time">🕐 ' + esc(t.due_time_fa) + '</span>' : '') + '</button>';
      }).join('') + '</div>';
    }).join('') : '<p class="small muted">در این ماه تسکی وجود ندارد.</p>');
    $$('#calAgenda [data-task]').forEach(function (el) {
      el.addEventListener('click', function () { openTask(Number(this.getAttribute('data-task'))); });
    });
    // سرستون‌ها حالا داخل #calGrid هستند؛ ظرف قدیمی خالی می‌ماند
    var oldHead = document.getElementById('calWeekdays');
    if (oldHead) oldHead.style.display = 'none';
    $$('#calGrid .cal-task').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.stopPropagation();
        openTask(Number(this.getAttribute('data-task')));
      });
    });
    $$('#calGrid .cal-day[data-date]').forEach(function (el) {
      el.addEventListener('click', function () {
        openTaskForm(null, { due_date_jalali: J.gregorianToJalaliInput(this.getAttribute('data-date')) });
      });
    });
  }
  function hexAlpha(hex, a) {
    hex = String(hex || '#0f766e').replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(function (c) { return c + c; }).join('');
    var r = parseInt(hex.slice(0, 2), 16), g = parseInt(hex.slice(2, 4), 16), b = parseInt(hex.slice(4, 6), 16);
    if (isNaN(r)) return 'rgba(15,118,110,' + a + ')';
    return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
  }
  function initCalendar() {
    $('#calPrev').addEventListener('click', function () {
      var m = state.calMonth - 1, y = state.calYear;
      if (m < 1) { m = 12; y--; }
      loadCalendar(y, m);
    });
    $('#calNext').addEventListener('click', function () {
      var m = state.calMonth + 1, y = state.calYear;
      if (m > 12) { m = 1; y++; }
      loadCalendar(y, m);
    });
    $('#calToday').addEventListener('click', function () { var t = J.todayJalali(); loadCalendar(t[0], t[1]); });
    $('#btnCalNewTask').addEventListener('click', function () {
      var today = J.todayJalali();
      var same = today[0] === state.calYear && today[1] === state.calMonth;
      openTaskForm(null, { due_date_jalali: same ? APP.today.jalali : (state.calYear + '/' + String(state.calMonth).padStart(2, '0') + '/01') });
    });
  }

  /* ================= کارمندان ================= */
  function loadEmployees() {
    api('users_list').then(function (res) {
      state.users = res.users || [];
      renderEmployees();
    }).catch(function (err) {
      $('#employeesBody').innerHTML = '<tr><td colspan="8" class="center muted">' + esc(err.message) + '</td></tr>';
    });
  }
  function renderEmployees() {
    var q = ($('#empSearch').value || '').trim();
    var rows = state.users.filter(function (u) {
      if (!q) return true;
      var hay = (u.full_name + ' ' + u.employee_code + ' ' + (u.position_title || '') + ' ' + u.username).toLowerCase();
      return hay.indexOf(q.toLowerCase()) !== -1;
    });
    var body = $('#employeesBody');
    if (!rows.length) { body.innerHTML = '<tr><td colspan="8" class="center muted">کارمندی یافت نشد.</td></tr>'; return; }
    body.innerHTML = rows.map(function (u) {
      return '<tr>' +
        '<td><div style="display:flex;gap:8px;align-items:center">' + avatar(u.full_name, u.avatar_color, 'avatar-sm') + '<div><div style="font-weight:700">' + esc(u.full_name) + '</div><div class="small muted" dir="ltr">' + esc(u.username) + '</div></div></div></td>' +
        '<td><code>' + esc(u.employee_code) + '</code></td>' +
        '<td>' + esc(u.position_title || '—') + '</td>' +
        '<td><span class="badge ' + (u.role === 'admin' ? 'badge-urgent' : u.role === 'manager' ? 'badge-review' : 'badge-todo') + '">' + esc(u.role_label) + '</span></td>' +
        '<td>' + FAT(u.tasks_open) + ' باز / ' + FAT(u.tasks_count) + '</td>' +
        '<td>' + (u.is_active ? '<span class="badge badge-active">فعال</span>' : '<span class="badge badge-archived">غیرفعال</span>') + '</td>' +
        '<td class="small">' + esc(u.last_login_human) + '</td>' +
        '<td><div class="row-actions">' +
        '<button class="btn btn-sm" data-emp-edit="' + u.id + '">✏️</button>' +
        '<button class="btn btn-sm" data-emp-task="' + u.id + '">➕ تسک</button>' +
        (APP.user.is_admin ? '<button class="btn btn-sm btn-danger" data-emp-del="' + u.id + '">🗑️</button>' : '') +
        '</div></td></tr>';
    }).join('');
    $$('[data-emp-edit]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = Number(this.getAttribute('data-emp-edit'));
        employeeModal(state.users.filter(function (u) { return u.id === id; })[0]);
      });
    });
    $$('[data-emp-task]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = Number(this.getAttribute('data-emp-task'));
        openTaskForm(null, { assigned_to: id });
      });
    });
    $$('[data-emp-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('کارمند حذف شود؟ تسک‌های او به شما منتقل می‌شود.')) return;
        api('user_delete', { id: Number(this.getAttribute('data-emp-del')) }).then(function (r) { toast(r.message || 'حذف شد.'); loadEmployees(); }).catch(function (e) { toast(e.message, 'error'); });
      });
    });
  }

  function employeeModal(user) {
    var u = user || {};
    openGeneric(user ? '✏️ ویرایش کارمند «' + u.full_name + '»' : '👥 معرفی کارمند جدید', '' +
      '<div class="form-row"><label class="field"><span>نام و نام خانوادگی *</span><input type="text" id="emName" value="' + esc(u.full_name || '') + '"></label>' +
      '<label class="field"><span>کد پرسنلی</span><input type="text" id="emCode" dir="ltr" value="' + esc(u.employee_code || '') + '" ' + (user ? 'disabled' : '') + ' placeholder="خودکار"></label></div>' +
      (user ? '' : '<div class="form-row"><label class="field"><span>نام کاربری *</span><input type="text" id="emUsername" dir="ltr" placeholder="ali"></label>' +
      '<label class="field"><span>رمز عبور *</span><input type="text" id="emPassword" dir="ltr" placeholder="حداقل ۶ کاراکتر"></label></div>') +
      '<div class="form-row"><label class="field"><span>ایمیل</span><input type="email" id="emEmail" dir="ltr" value="' + esc(u.email || '') + '"></label>' +
      '<label class="field"><span>شماره همراه</span><input type="text" id="emPhone" dir="ltr" value="' + esc(u.phone || '') + '"></label></div>' +
      '<div class="form-row"><label class="field"><span>سمت سازمانی</span><input type="text" id="emPosition" value="' + esc(u.position_title || '') + '" placeholder="مثلاً کارشناس فروش"></label>' +
      '</div>' +
      '<div class="form-row"><label class="field"><span>نقش سیستمی</span><select id="emRole">' +
      '<option value="employee"' + (u.role === 'employee' ? ' selected' : '') + '>کارمند</option>' +
      '<option value="manager"' + (u.role === 'manager' ? ' selected' : '') + '>مدیر</option>' +
      (APP.user.is_admin ? '<option value="admin"' + (u.role === 'admin' ? ' selected' : '') + '>مدیر شرکت</option>' : '') +
      '</select></label>' +
      '<label class="field"><span>تاریخ استخدام (شمسی)</span><input type="text" id="emHire" dir="ltr" value="' + esc(u.hire_date ? J.gregorianToJalaliInput(u.hire_date) : APP.today.jalali) + '"></label></div>' +
      '<label class="field"><span>درباره</span><textarea id="emBio" rows="2">' + esc(u.bio || '') + '</textarea></label>' +
      (user ? '<div class="checkline"><input type="checkbox" id="emActive"' + (u.is_active ? ' checked' : '') + '><label for="emActive">حساب فعال باشد</label></div>' +
        '<label class="field"><span>رمز عبور جدید (اختیاری)</span><input type="text" id="emPassword" dir="ltr" placeholder="برای تغییر رمز وارد کنید"></label>' : '') +
      '<button class="btn btn-primary btn-block" id="emSave">💾 ذخیره</button>');

    $('#emSave').addEventListener('click', function () {
      var hire = $('#emHire').value.trim();
      var hireG = hire ? J.parseToGregorian(hire) : '';
      if (hire && !hireG) { toast('تاریخ استخدام نامعتبر است.', 'error'); return; }
      var payload = {
        full_name: $('#emName').value.trim(),
        email: $('#emEmail').value.trim(),
        phone: $('#emPhone').value.trim(),
        position_title: $('#emPosition').value.trim(),
        role: $('#emRole').value,
        hire_date: hireG || '',
        bio: $('#emBio').value.trim()
      };
      if (!payload.full_name) { toast('نام کارمند الزامی است.', 'error'); return; }
      if (!user) {
        payload.username = $('#emUsername').value.trim();
        payload.password = $('#emPassword').value;
        payload.employee_code = $('#emCode').value.trim();
        if (!payload.username || !payload.password) { toast('نام کاربری و رمز عبور الزامی است.', 'error'); return; }
      } else {
        payload.id = user.id;
        payload.is_active = $('#emActive').checked ? 1 : 0;
        var pw = $('#emPassword').value;
        if (pw) payload.password = pw;
      }
      api(user ? 'user_update' : 'user_create', payload).then(function (r) {
        toast(r.message || 'ذخیره شد.');
        closeGeneric();
        loadEmployees();
      }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  /* ================= اطلاعیه‌ها ================= */
  function loadAnnouncements() {
    api('announcements_list').then(function (res) {
      var wrap = $('#announcementsList');
      if (!res.announcements.length) { wrap.innerHTML = emptyState('📢', 'اطلاعیه‌ای منتشر نشده است.'); return; }
      wrap.innerHTML = res.announcements.map(function (a) {
        var prio = { normal: 'badge-todo', important: 'badge-review', urgent: 'badge-urgent' }[a.priority] || 'badge-todo';
        var prioLabel = { normal: 'عادی', important: 'مهم', urgent: 'فوری' }[a.priority] || '';
        return '<div class="ann-card' + (a.is_pinned ? ' pinned' : '') + '">' +
          '<div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap"><h4 style="flex:1">' + (a.is_pinned ? '📌 ' : '') + esc(a.title) + '</h4>' +
          '<span class="badge ' + prio + '">' + prioLabel + '</span>' +
          (APP.user.is_manager ? '<button class="btn btn-sm btn-danger" data-ann-del="' + a.id + '">🗑️</button>' : '') + '</div>' +
          '<p style="margin:8px 0">' + esc(a.body) + '</p>' +
          '<div class="small muted">✍️ ' + esc(a.creator_name) + ' • ' + esc(a.created_ago) + (a.expires_jalali ? ' • معتبر تا ' + esc(a.expires_jalali) : '') + '</div></div>';
      }).join('');
      $$('[data-ann-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('اطلاعیه حذف شود؟')) return;
          api('announcement_delete', { id: Number(this.getAttribute('data-ann-del')) }).then(function () { toast('حذف شد.'); loadAnnouncements(); }).catch(function (e) { toast(e.message, 'error'); });
        });
      });
    }).catch(function (err) { $('#announcementsList').innerHTML = emptyState('⚠️', err.message); });
  }
  function announcementModal() {
    openGeneric('📢 اطلاعیه جدید', '' +
      '<label class="field"><span>عنوان *</span><input type="text" id="anTitle"></label>' +
      '<label class="field"><span>متن اطلاعیه *</span><textarea id="anBody" rows="4"></textarea></label>' +
      '<div class="form-row"><label class="field"><span>اهمیت</span><select id="anPrio"><option value="normal">عادی</option><option value="important">مهم</option><option value="urgent">فوری</option></select></label>' +
      '</div>' +
      '<label class="field"><span>معتبر تا تاریخ (شمسی، اختیاری)</span><input type="text" id="anExpires" dir="ltr" placeholder="۱۴۰۵/۱۲/۲۹"></label>' +
      '<div class="checkline"><input type="checkbox" id="anPinned"><label for="anPinned">سنجاق شود در بالای لیست</label></div>' +
      '<button class="btn btn-primary btn-block" id="anSave">📢 انتشار اطلاعیه</button>');
    $('#anSave').addEventListener('click', function () {
      var exp = $('#anExpires').value.trim();
      var expG = exp ? J.parseToGregorian(exp) : null;
      if (exp && !expG) { toast('تاریخ انقضا نامعتبر است.', 'error'); return; }
      api('announcement_create', {
        title: $('#anTitle').value.trim(), body: $('#anBody').value.trim(),
        priority: $('#anPrio').value,
        is_pinned: $('#anPinned').checked ? 1 : 0, expires_at: expG || ''
      }).then(function (r) {
        toast(r.message || 'منتشر شد.');
        closeGeneric();
        loadAnnouncements();
      }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  /* ================= اعلان‌ها ================= */
  function loadNotifications() {
    var version = notifVersion;
    api('notifications_list').then(function (res) {
      if (version !== notifVersion || notifPending) return;
      var wrap = $('#notificationsList');
      if (!res.notifications.length) { wrap.innerHTML = emptyState('🔔', 'اعلانی ندارید.'); return; }
      wrap.innerHTML = res.notifications.map(function (n) {
        return '<div class="notif-item' + (n.is_read ? '' : ' unread') + '" data-notif="' + n.id + '" data-notif-type="' + esc(n.entity_type || n.related_type || '') + '" data-notif-rel="' + (n.entity_id || n.related_id || '') + '">' +
          '<span style="font-size:20px">' + (n.is_read ? '📭' : '📬') + '</span>' +
          '<div class="grow"><h5>' + esc(n.title) + '</h5><p>' + esc(n.message || n.body) + '</p>' +
          '<div class="small muted">' + esc(n.created_ago) + ' • ' + esc(n.created_human) + '</div></div></div>';
      }).join('');
      $$('#notificationsList .notif-item').forEach(function (item) {
        item.addEventListener('click', function () {
          markNotification(Number(this.getAttribute('data-notif')), this.classList.contains('unread'));
          var type = this.getAttribute('data-notif-type');
          var rel = this.getAttribute('data-notif-rel');
          if (type === 'task' && rel) { go('tasks'); openTask(Number(rel)); }
          else if (type === 'announcement') { go('announcements'); }
        });
      });
    }).catch(function (err) { $('#notificationsList').innerHTML = emptyState('⚠️', err.message); });
  }
  // تغییر فوری و سپس همگام‌سازی با سرور؛ خطا باعث بازگرداندن وضعیت واقعی می‌شود.
  function markNotification(id, wasUnread) {
    if (notifPendingIds[id]) return;
    notifPendingIds[id] = true;
    notifPending++;
    notifVersion++;
    var item = document.querySelector('#notificationsList [data-notif="' + id + '"]');
    if (item) {
      wasUnread = item.classList.contains('unread');
    }
    if (item && wasUnread) {
      item.classList.remove('unread');
      var icon = item.querySelector('span');
      if (icon) icon.textContent = '📭';
    }
    if (wasUnread) setNotifBadge(state.unreadNotifs - 1);
    api('notification_read', { id: id }).catch(function (err) {
      toast(err.message || 'خواندن اعلان ثبت نشد.', 'error');
    }).then(function () {
      delete notifPendingIds[id];
      notifPending--;
      notifVersion++;
      if (!notifPending) {
        refreshNotifCount();
        if (state.section === 'notifications') loadNotifications();
      }
    });
  }
  /* ---------------- اعلان‌های زنده ---------------- */
  var notifVersion = 0;
  var notifPending = 0;
  var notifPendingIds = {};
  var notifTimer = null;       // شناسهٔ تایمر نظرسنجی
  var notifSeenLastId = 0;     // بزرگ‌ترین شناسهٔ دیده‌شده (برای تشخیص اعلان تازه)
  var notifBooting = true;     // اولین بار نباید برای اعلان‌های قدیمی نوتیف بدهد
  var notifSeenIds = {};       // شناسه‌های اعلان که قبلاً نمایش داده شده‌اند

  /** به‌روزرسانی نشانگر زنگ (بدون رفت‌وبرگشت شبکه) */
  function setNotifBadge(unread) {
    unread = Math.max(0, Number(unread) || 0);
    state.unreadNotifs = unread;
    var dot = $('#notifDot');
    if (dot) { dot.textContent = FAT(unread); dot.style.display = unread > 0 ? '' : 'none'; }
    var nav = $('#notifNavBadge');
    if (nav) { nav.textContent = FAT(unread); nav.style.display = unread > 0 ? '' : 'none'; }
    // برای دسترس‌پذیری
    var bell = $('#btnNotifBell');
    if (bell) bell.setAttribute('aria-label', unread > 0 ? FAT(unread) + ' اعلان خوانده‌نشده' : 'اعلان‌ها');
  }

  /**
   * نمایش اعلان تازه در گوشهٔ صفحه — همان لحظه‌ای که سرور آن را دارد.
   * کلیک روی کارت، مستقیم به همان اعلان/تسک می‌برد.
   */
  function showNotifPopup(n) {
    var wrap = document.getElementById('notifPopWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'notifPopWrap';
      wrap.className = 'notif-pop-wrap';
      document.body.appendChild(wrap);
    }
    var el = document.createElement('div');
    el.className = 'notif-pop';
    var icon = n.type === 'leave_pending' || n.type === 'leave_reviewed' ? '🌴'
      : n.type === 'task_review' ? '✅'
      : n.type === 'task_assigned' || n.type === 'task' ? '📋'
      : n.type === 'announcement' ? '📢' : '🔔';
    el.innerHTML =
      '<div class="notif-pop-head">' + icon + ' <strong>' + esc(n.title || 'اعلان جدید') + '</strong></div>' +
      '<div class="notif-pop-body">' + esc(n.message || '') + '</div>' +
      '<div class="notif-pop-foot"><span class="small muted">' + esc(n.created_ago || 'همین حالا') + '</span>' +
      '<button type="button" class="btn btn-sm btn-ghost notif-pop-close">بستن</button></div>';
    wrap.appendChild(el);
    // انیمیشن ورود
    requestAnimationFrame(function () { el.classList.add('in'); });
    // کلیک روی بدنه: رفتن به مقصد
    el.addEventListener('click', function (e) {
      if (e.target.classList.contains('notif-pop-close')) { dismiss(); return; }
      var type = n.entity_type || n.related_type || '';
      var rel = n.entity_id || n.related_id || '';
      markNotification(n.id, !n.is_read);
      if (type === 'task' && rel) { go('tasks'); openTask(Number(rel)); }
      else if (type === 'announcement') { go('announcements'); }
      else { go('notifications'); }
      dismiss();
    });
    var hideTimer = setTimeout(dismiss, 9000);
    function dismiss() {
      clearTimeout(hideTimer);
      el.classList.remove('in');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 260);
    }
  }

  function showDeviceNotification(n) {
    if (!window.isSecureContext || !('Notification' in window) || Notification.permission !== 'granted') return;
    var options = { body: 'اعلان تازه‌ای در همکار پلاس دارید.', icon: 'assets/icons/app-192.png', tag: 'hamkar-' + n.id };
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistration().then(function (registration) {
        if (registration && registration.showNotification) return registration.showNotification('اعلان جدید', options);
        if (typeof Notification === 'function') new Notification('اعلان جدید', options);
      }).catch(function () {});
    } else {
      try { new Notification('اعلان جدید', options); } catch (e) {}
    }
  }

  /**
   * نظرسنجی سبک: هر ۱۵ ثانیه فقط «تعداد نخوانده» و «آخرین شناسه» را می‌پرسد.
   * با اعلان تازه (last_id بالاتر)، فهرست را می‌گیرد و برای هر آیتم تازه
   * کارت گوشهٔ صفحه نشان می‌دهد.
   */
  function pollNotifications() {
    if (notifPending) return;
    var version = notifVersion;
    api('notifications_unread').then(function (res) {
      if (version !== notifVersion || notifPending) return;
      var unread = Number(res.unread || 0);
      var lastId = Number(res.last_id || 0);
      setNotifBadge(unread);

      var isNew = lastId > notifSeenLastId;
      if (!isNew || notifBooting) {
        notifSeenLastId = Math.max(notifSeenLastId, lastId);
        notifBooting = false;
        return;
      }

      // اعلان تازه داریم: جزئیاتش را بگیر و نمایش بده
      api('notifications_list').then(function (list) {
        if (version !== notifVersion || notifPending) return;
        notifSeenLastId = Math.max(notifSeenLastId, lastId);
        var fresh = (list.notifications || []).filter(function (x) {
          return !notifSeenIds[x.id] && !x.is_read;
        });
        // فقط شناسه‌های همان ۵۰ مورد اخیر را نگه می‌داریم (جلوگیری از رشد بی‌پایان)
        notifSeenIds = {};
        (list.notifications || []).forEach(function (x) { notifSeenIds[x.id] = 1; });
        // فقط ۳ مورد آخر را نوتیف کن (نه سیل کارت)
        fresh.sort(function (a, b) { return a.id - b.id; });
        fresh.forEach(showDeviceNotification);
        fresh.slice(-3).forEach(showNotifPopup);
        // اگر صفحهٔ اعلان‌ها باز است، فهرست را هم تازه کن
        if (state.section === 'notifications') loadNotifications();
      }).catch(function () {});
    }).catch(function () {});
  }

  /** همگام‌سازی فوری نشانگر با سرور (پس از هر تغییر) */
  function refreshNotifCount() {
    if (notifPending) return;
    var version = notifVersion;
    api('notifications_unread').then(function (res) {
      if (version === notifVersion && !notifPending) setNotifBadge(Number(res.unread || 0));
    }).catch(function () {});
  }

  function initNotifPolling() {
    if (notifTimer) clearInterval(notifTimer);
    pollNotifications();
    notifTimer = setInterval(pollNotifications, 15000);
    // وقتی کاربر برمی‌گردد به تب، فوراً تازه کن (نه ۱۵ ثانیه صبر)
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) pollNotifications();
    });
  }

  /* Refresh the active view while preserving open forms and task drafts. */
  function canRefreshLive() {
    if (document.hidden || document.querySelector('.modal-backdrop.open, .drawer.open')) return false;
    var active = document.activeElement;
    return !(active && active.matches && active.matches('input, textarea, select, [contenteditable="true"]'));
  }
  function refreshLiveView() {
    if (!canRefreshLive()) return;
    switch (state.section) {
      case 'reports': loadDashboard(); break;
      case 'tasks': loadTasks(true); break;
      case 'calendar': loadCalendar(state.calYear, state.calMonth, true); break;
      case 'employees': loadEmployees(); break;
      case 'announcements': loadAnnouncements(); break;
      case 'notifications': if (!notifPending) loadNotifications(); break;
      case 'activity': loadActivity(); break;
    }
  }
  function updateLiveSelect(select, items, emptyLabel) {
    if (!select) return;
    var current = select.value;
    var selectedValues = select.multiple ? Array.from(select.selectedOptions).map(function(o) { return o.value; }) : [];
    var fragment = document.createDocumentFragment();
    if (emptyLabel !== null) {
      var empty = document.createElement('option'); empty.value = ''; empty.textContent = emptyLabel; fragment.appendChild(empty);
    }
    items.forEach(function (item) {
      var option = document.createElement('option'); option.value = String(item.id);
      option.textContent = item.name || item.full_name || '';
      fragment.appendChild(option);
    });
    select.replaceChildren(fragment);
    if (select.multiple) Array.from(select.options).forEach(function(o) { o.selected = selectedValues.includes(o.value); });
    else if (Array.prototype.some.call(select.options, function (option) { return option.value === current; })) select.value = current;
  }
  function refreshLiveReferences() {
    if (!canRefreshLive()) return;
    api('live_reference').then(function (data) {
      if (!canRefreshLive() || !Array.isArray(data.users)) return;
      var users = data.users;
      if (JSON.stringify(users.map(function (u) { return [u.id, u.full_name, u.department_name]; })) !==
          JSON.stringify((APP.users || []).map(function (u) { return [u.id, u.full_name, u.department_name]; }))) {
        APP.users = users;
        if (window.VoiceAI && VoiceAI.options) VoiceAI.options.users = users;
        updateLiveSelect($('#tfAssignee'), users, null);
        updateLiveSelect($('#tfAssignees'), users, null);
        updateLiveSelect($('#taskUserFilter'), users, 'همه کارمندان');
        var template = document.getElementById('voiceTaskTpl');
        if (template && template.content) updateLiveSelect(template.content.querySelector('[data-f="assigned_to"]'), users, null);
      }

    }).catch(function () {});
  }
  function initLiveView() {
    setInterval(refreshLiveView, 15000);
    setInterval(refreshLiveReferences, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshLiveView(); });
    window.addEventListener('online', function () { refreshLiveView(); refreshLiveReferences(); });
  }

  /* ================= فعالیت‌ها ================= */
  function loadActivity() {
    $('#activityBody').innerHTML = '<tr><td colspan="4"><div class="loading"><div class="spinner"></div></div></td></tr>';
    api('activity_list').then(function (res) {
      if (!res.logs.length) { $('#activityBody').innerHTML = '<tr><td colspan="4" class="center muted">فعالیتی ثبت نشده است.</td></tr>'; return; }
      var icons = { create_task: '➕', update_task: '✏️', delete_task: '🗑️', comment: '💬', attach: '📎', clock_in: '⏰', clock_out: '🚪', leave_request: '🌴', leave_review: '✅', create_user: '👥', update_user: '👤', delete_user: '❌', create_project: '🚀', create_department: '🏢', announcement: '📢', voice_preview: '🎙️', settings: '⚙️', password_change: '🔑' };
      $('#activityBody').innerHTML = res.logs.map(function (l) {
        return '<tr><td>' + esc(l.user_name || 'سیستم') + '</td><td>' + (icons[l.action] || '•') + ' ' + esc(l.action_label || l.action) + '</td>' +
          '<td class="small">' + esc(l.description) + '</td><td class="small">' + esc(l.created_human) + '<div class="muted">' + esc(l.created_ago) + '</div></td></tr>';
      }).join('');
    }).catch(function (err) { $('#activityBody').innerHTML = '<tr><td colspan="4" class="center muted">' + esc(err.message) + '</td></tr>'; });
  }

  /* ================= پروفایل ================= */
  function initProfile() {
    $('#btnSaveProfile').addEventListener('click', function () {
      api('profile_update', {
        full_name: $('#pfFullName').value.trim(), email: $('#pfEmail').value.trim(),
        phone: $('#pfPhone').value.trim(), position_title: $('#pfPosition').value.trim(),
        bio: $('#pfBio').value.trim()
      }).then(function (r) { toast(r.message || 'ذخیره شد.'); }).catch(function (e) { toast(e.message, 'error'); });
    });
    $('#btnChangePassword').addEventListener('click', function () {
      api('password_change', {
        current_password: $('#pwCurrent').value, new_password: $('#pwNew').value,
        new_password_confirm: $('#pwConfirm').value
      }).then(function (r) {
        toast(r.message || 'تغییر کرد.');
        $('#pwCurrent').value = ''; $('#pwNew').value = ''; $('#pwConfirm').value = '';
      }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  /* ================= تنظیمات ================= */
  function loadSettings() {
    if (!APP.user.is_admin) return;
    api('settings_get').then(function (res) {
      var s = res.settings;
      $('#stCompanyName').value = s.company_name || '';
      $('#stSlogan').value = s.company_slogan || '';
      $('#stWorkStart').value = s.work_start_time || '08:30';
      $('#stWorkEnd').value = s.work_end_time || '17:00';
      $('#stAiProvider').value = s.ai_provider || 'auto';
      // 🔒 سرور فقط نسخه ماسک‌شده را می‌فرستد؛ خالی‌گذاشتن کادر = حفظ کلید فعلی
      $('#stGroqKey').value = s.groq_api_key || '';
      $('#stGroqKey').placeholder = s.groq_api_key_set ? 'کلید ثبت شده — برای تغییر، کلید جدید را وارد کنید' : 'gsk_...';
      $('#stGeminiKey').value = s.gemini_api_key || '';
      $('#stGeminiKey').placeholder = s.gemini_api_key_set ? 'کلید ثبت شده — برای تغییر، کلید جدید را وارد کنید' : 'AIza...';
      var groqState = document.getElementById('stGroqKeyState');
      if (groqState) {
        groqState.textContent = s.groq_api_key_set
          ? '✅ کلید Groq ثبت شده است. برای تغییر، کلید جدید را وارد کنید یا از دکمهٔ «حذف کلید» استفاده کنید.'
          : '⚠️ کلیدی ثبت نشده است — بدون کلید، تشخیص گفتار فقط در مرورگر انجام می‌شود.';
      }
      var cg = document.getElementById('btnClearGroqKey');
      if (cg) cg.classList.toggle('hidden', !s.groq_api_key_set);
      var cg2 = document.getElementById('btnClearGeminiKey');
      if (cg2) cg2.classList.toggle('hidden', !s.gemini_api_key_set);
      $('#stSelfTask').checked = s.allow_employee_self_task === '1';
      $('#stPeerTask').checked = s.allow_employee_peer_task === '1';
      var om = document.getElementById('stOfflineMode');
      if (om) om.checked = String(s.ai_offline_mode) === '1';
      updateOfflineHint();
    }).catch(function (err) { toast(err.message, 'error'); });
    api('db_status').then(function (res) {
      var db = res.db;
      var tables = db.tables || [];
      $('#dbStatusBox').innerHTML =
        '<dl class="kv">' +
        '<dt>درایور فعال</dt><dd><span class="badge badge-active">' + esc((db.driver || '').toUpperCase()) + '</span> <span class="small muted">(تنظیم: ' + esc(db.configured_as || 'auto') + ')</span></dd>' +
        '<dt>موتور SQL</dt><dd>' + esc(db.engine_label || '—') + '</dd>' +
        '<dt>نسخه سرور دیتابیس</dt><dd dir="ltr">' + esc(db.version || '—') + '</dd>' +
        '<dt>هاست MySQL</dt><dd dir="ltr">' + esc(db.mysql_host || '—') + '</dd>' +
        '<dt>نام دیتابیس MySQL</dt><dd dir="ltr">' + esc(db.mysql_db || '—') + '</dd>' +
        '<dt>مجموعه نویسه</dt><dd><code>' + esc(db.charset || 'utf8mb4') + '</code> / <code>' + esc(db.collation || 'utf8mb4_persian_ci') + '</code></dd>' +
        '<dt>پشتیبانی فارسی</dt><dd>' + (db.persian_ready ? '<span class="badge badge-done">کامل ✅</span>' : '—') + '</dd>' +
        '<dt>ساخت خودکار جداول</dt><dd>' + (db.auto_schema ? '<span class="badge badge-done">فعال</span>' : '—') + '</dd>' +
        '<dt>نسخه PHP</dt><dd dir="ltr">' + esc(db.php_version) + '</dd>' +
        '<dt>منطقه زمانی</dt><dd>' + esc(db.timezone) + '</dd>' +
        '<dt>امروز</dt><dd>' + esc(db.today_jalali) + '</dd>' +
        (db.fallback_reason ? '<dt>علت جایگزینی</dt><dd class="small">' + esc(db.fallback_reason) + '</dd>' : '') +
        '</dl>' +
        '<div class="small muted" style="margin-top:10px;line-height:1.9">' +
        '🔁 برای انتقال داده‌های همین پایگاه داده به MySQL، ' +
        '<a class="link" href="migrate.php">ابزار انتقال داده</a> را اجرا کنید.' +
        '</div>' +
        '<h4 style="font-size:13px;margin:14px 0 8px">🗂️ جداول (' + FAT(tables.length) + ')</h4>' +
        tables.map(function (t) {
          var name = typeof t === 'string' ? t : (t.name || t.table || t.TABLE_NAME || '');
          var label = typeof t === 'object' && t !== null ? (t.label || '') : '';
          var rows = typeof t === 'object' && t !== null && t.rows !== undefined ? t.rows : null;
          var exists = typeof t === 'object' && t !== null ? t.exists !== false : true;
          return '<div class="db-table-row"><span>📋</span><span class="grow"><strong dir="ltr">' + esc(name) + '</strong>' + (label ? ' <span class="small muted">— ' + esc(label) + '</span>' : '') + '</span>' +
            (rows !== null ? '<span class="badge badge-active">' + FAT(rows) + ' ردیف</span>' : '') +
            (exists ? '<span class="badge badge-done">آماده</span>' : '<span class="badge badge-urgent">ایجاد می‌شود</span>') + '</div>';
        }).join('');
    }).catch(function (err) { $('#dbStatusBox').innerHTML = emptyStr(err.message); });
    renderAiStatus();
  }

  /**
   * نمایش وضعیت سرویس تشخیص گفتار (STT) و دسترسی سرور به سرویس‌های رایگان
   */
  function renderAiStatus() {
    var box = $('#aiStatusBox');
    if (!box) return;
    box.innerHTML = '<div class="loading"><div class="spinner"></div>در حال بررسی...</div>';
    api('ai_status').then(function (res) {
      var ai = res.ai || {};
      var reach = ai.reachability || {};
      var engineRow = ai.engine === 'local'
        ? '<div class="alert alert-success" style="margin-bottom:10px">🔌 <strong>موتور فعال: داخلی (آفلاین)</strong> — ' +
          (ai.offline_mode
            ? 'حالت آفلاین به‌صورت دستی روشن است.'
            : 'سرور به سرویس‌های بین‌المللی دسترسی ندارد؛ برنامه خودکار از موتور فارسی داخلی استفاده می‌کند.') +
          ' این حالت بدون اینترنت بین‌الملل کامل کار می‌کند.</div>'
        : '<div class="alert alert-info" style="margin-bottom:10px">☁️ <strong>موتور فعال: ابری</strong> — ' +
          'سرویس‌های رایگان بین‌المللی در دسترس هستند. اگر اینترنت قطع شود، برنامه خودکار به موتور داخلی برمی‌گردد.</div>';
      var keys = [
        { label: 'Groq Whisper (دقیق‌ترین، نیازمند کلید رایگان)', on: ai.groq_key_set, net: reach.groq },
        { label: 'Gemini 2.5 Flash (نیازمند کلید رایگان)', on: ai.gemini_key_set, net: reach.gemini },
        { label: 'Pollinations Whisper (بدون کلید)', on: true, net: reach.pollinations }
      ];
      var rows = keys.map(function (k) {
        var net = k.net || {};
        var state = k.on
          ? (net.ok ? '<span class="badge badge-done">آماده</span>' : '<span class="badge badge-urgent">شبکه مسدود</span>')
          : '<span class="badge">کلید ندارد</span>';
        return '<div class="db-table-row"><span>🎙️</span><span class="grow">' + esc(k.label) +
          (net.detail ? ' <span class="small muted" dir="ltr">— ' + esc(net.detail) + '</span>' : '') +
          '</span>' + state + '</div>';
      }).join('');
      var checks = ai.checks || {};
      box.innerHTML = engineRow + rows +
        '<div class="small" style="margin-top:8px;line-height:1.9">' + esc(ai.advice || '') + '</div>' +
        '<div class="small muted" dir="ltr" style="margin-top:6px">cURL: ' + (checks.curl ? '✓' : '✗') +
        ' · upload_max_filesize: ' + esc(checks.upload_max_filesize || '—') +
        ' · post_max_size: ' + esc(checks.post_max_size || '—') + '</div>';
    }).catch(function (err) {
      box.innerHTML = '<div class="small" style="color:var(--danger)">' + esc(err.message || 'بررسی ناموفق') + '</div>';
    });
  }

  /** به‌روزرسانی متن راهنمای زیر کارت حالت آفلاین */
  function updateOfflineHint() {
    var hint = document.getElementById('offlineHint');
    var el = document.getElementById('stOfflineMode');
    if (!hint || !el) return;
    if (el.checked) {
      hint.className = 'small';
      hint.style.color = 'var(--primary)';
      hint.innerHTML = '✅ فعال است — هیچ ترافیک بین‌المللی ارسال نمی‌شود. همهٔ امکانات (تسک، کانبان، اعلان، تقویم و پردازش فارسی) کار می‌کند. برای صدا، متن را در کادر بنویسید؛ «تشخیص گفتار مرورگر» رایگان است ولی در کروم به اینترنت نیاز دارد.';
    } else {
      hint.className = 'small muted';
      hint.innerHTML = 'ℹ️ خاموش است. اگر اینترنت بین‌الملل قطع شود، برنامه خودکار تشخیص می‌دهد و به موتور داخلی برمی‌گردد (بدون معطل‌شدن).';
    }
  }

  function initAiCheckButton() {
    var btn = $('#btnAiCheck');
    if (btn) btn.addEventListener('click', renderAiStatus);
    var om = document.getElementById('stOfflineMode');
    if (om) om.addEventListener('change', updateOfflineHint);
  }

  /** حذف صریح کلید API (سرور با مقدار «__clear__» آن را پاک می‌کند) */
  function bindClearKey(btnId, field, label) {
    var b = document.getElementById(btnId);
    if (!b) return;
    b.addEventListener('click', function () {
      if (!confirm('کلید ' + label + ' حذف شود؟ بعد از حذف، این سرویس دیگر استفاده نمی‌شود.')) return;
      var payload = { }; payload[field] = '__clear__';
      api('settings_save', payload).then(function (r) {
        toast(r.message || 'کلید حذف شد.');
        loadSettings();
        renderAiStatus();
      }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  function initSettingsSave() {
    var btn = $('#btnSaveSettings');
    if (!btn) return;
    bindClearKey('btnClearGroqKey', 'groq_api_key', 'Groq');
    bindClearKey('btnClearGeminiKey', 'gemini_api_key', 'Gemini');
    btn.addEventListener('click', function () {
      api('settings_save', {
        company_name: $('#stCompanyName').value.trim(),
        company_slogan: $('#stSlogan').value.trim(),
        work_start_time: $('#stWorkStart').value,
        work_end_time: $('#stWorkEnd').value,
        ai_provider: $('#stAiProvider').value,
        ai_offline_mode: (function () { var el = document.getElementById('stOfflineMode'); return el && el.checked ? '1' : '0'; })(),
        groq_api_key: $('#stGroqKey').value.trim(),
        gemini_api_key: $('#stGeminiKey').value.trim(),
        allow_employee_self_task: $('#stSelfTask').checked ? '1' : '0',
        allow_employee_peer_task: $('#stPeerTask').checked ? '1' : '0'
      }).then(function (r) { toast(r.message || 'ذخیره شد.'); }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  /* ================= مودال عمومی ================= */
  function openGeneric(title, html) {
    $('#genericTitle').textContent = title;
    $('#genericBody').innerHTML = html;
    $('#modalGeneric').classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeGeneric() {
    $('#modalGeneric').classList.remove('open');
    document.body.style.overflow = '';
  }

  /* ================= کلیدهای میان‌بر ================= */
  function initShortcuts() {
    document.addEventListener('keydown', function (e) {
      if (e.target.matches('input, textarea, select')) return;
      if (e.key === 'n' || e.key === 'N') { e.preventDefault(); openTaskForm(null); }
      if (e.key === 'v' || e.key === 'V') { e.preventDefault(); VoiceAI.open(); }
      if (e.key === '/') { e.preventDefault(); go('tasks'); setTimeout(function () { $('#taskSearch').focus(); }, 100); }
    });
  }

  /* ================= راه‌اندازی ================= */
  function initGlobalButtons() {
    $('#btnQuickTask').addEventListener('click', function () { openTaskForm(null); });
    $('#btnQuickVoice').addEventListener('click', function () { VoiceAI.open(); });
    $('#btnNotifBell').addEventListener('click', function () { go('notifications'); refreshNotifCount(); });
    $('#btnFullscreen').addEventListener('click', function (e) {
      e.preventDefault();
      var doc = document.documentElement;
      if (!document.fullscreenElement) {
        (doc.requestFullscreen || doc.webkitRequestFullscreen || function () {}).call(doc);
      } else {
        (document.exitFullscreen || document.webkitExitFullscreen || function () {}).call(document);
      }
    });
    $('#btnNewEmployee') && $('#btnNewEmployee').addEventListener('click', function () { employeeModal(null); });
    $('#btnNewAnnouncement') && $('#btnNewAnnouncement').addEventListener('click', announcementModal);
    $('#btnReadAll').addEventListener('click', function () {
      var btn = this;
      notifPending++;
      notifVersion++;
      // ✅ تغییر فوری در رابط: قبل از پاسخ سرور، همه را «خوانده» نشان بده
      var items = $$('#notificationsList .notif-item');
      items.forEach(function (it) {
        it.classList.remove('unread');
        var ic = it.querySelector('span');
        if (ic) ic.textContent = '📭';
      });
      setNotifBadge(0);
      btn.disabled = true;
      api('notifications_read_all').then(function (r) {
        toast(r.message || 'همه اعلان‌ها خوانده شد.');
      }).catch(function (e) {
        toast(e.message, 'error');
      }).then(function () {
        notifPending--;
        notifVersion++;
        btn.disabled = false;
        if (!notifPending) {
          refreshNotifCount();
          if (state.section === 'notifications') loadNotifications();
        }
      });
    });
    $('#btnReloadActivity') && $('#btnReloadActivity').addEventListener('click', loadActivity);
    $('#empSearch') && $('#empSearch').addEventListener('input', renderEmployees);
    $('#genericClose').addEventListener('click', closeGeneric);
    $('#modalGeneric').addEventListener('click', function (e) { if (e.target === this) closeGeneric(); });
    window.addEventListener('resize', function () {
      clearTimeout(window.__rsz);
      window.__rsz = setTimeout(rerenderCharts, 250);
    });

    // ابزارهای مستقیم برای تست و استفاده از کنسول
    window.App = {
      toast: toast, api: api, go: go, openTask: openTask, openTaskForm: openTaskForm,
      refresh: loadDashboard, state: state
    };
  }

  document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    initClock();
    initLiveView();
    initNav();
    initTaskToolbar();
    initTaskForm();
    initDrawer();
    initCalendar();
    initProfile();
    initSettingsSave();
    initAiCheckButton();
    initShortcuts();
    initGlobalButtons();
    var lastSessionTouch = 0;
    function touchSession() {
      if (Date.now() - lastSessionTouch < 60000) return;
      lastSessionTouch = Date.now();
      api('session_touch', {}).catch(function () {});
    }
    document.addEventListener('pointerdown', touchSession, {passive:true});
    document.addEventListener('keydown', touchSession);
    $('#homeVoice').addEventListener('click', function () { VoiceAI.open(); VoiceAI.startRecord(); });
    $('#homeTaskDetails').addEventListener('click', function () { openTaskForm(null, {title: $('#homeTaskTitle').value.trim()}); });
    $('#homeTaskForm').addEventListener('submit', function (e) {
      e.preventDefault(); var input = $('#homeTaskTitle'), title = input.value.trim(); if (!title) return;
      var btn = this.querySelector('[type=submit]'); btn.disabled = true;
      api('task_create', {title:title, assigned_to:APP.user.id}).then(function (r) { input.value = ''; toast(r.message || 'تسک ثبت شد.'); }).catch(function (err) { toast(err.message, 'error'); }).finally(function () { btn.disabled = false; });
    });

    VoiceAI.init({
      api: api,
      users: APP.users,
      projects: APP.projects,
      isManager: APP.user.is_manager,
      autoCreate: false,
      onCreated: function (res) {
        var count = (res.tasks || res.ids || []).length;
        toast('🎉 ' + FAT(count) + ' تسک صوتی در تقویم شمسی ثبت شد.', 'success');
        if (state.section === 'tasks') loadTasks();
        if (state.section === 'reports') loadDashboard();
        if (state.section === 'calendar') loadCalendar(state.calYear, state.calMonth);
      },
      onManual: function () { openTaskForm(null); }
    });
    // شناسه‌های موجود را «دیده‌شده» علامت بزن تا برای اعلان‌های قدیمی نوتیف ندهیم
    api('notifications_list').then(function (res) {
      notifSeenIds = {};
      (res.notifications || []).forEach(function (x) { notifSeenIds[x.id] = 1; });
      notifSeenLastId = (res.notifications || []).reduce(function (m, x) { return Math.max(m, Number(x.id) || 0); }, 0);
      notifBooting = false;
      initNotifPolling();
    }).catch(function () { initNotifPolling(); });
  });
})();
