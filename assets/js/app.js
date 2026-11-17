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
    dashboard: null
  };

  /* ---------------- ابزار پایه ---------------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, type) {
    if (!msg) return;
    var wrap = $('#toastWrap');
    var el = document.createElement('div');
    el.className = 'toast' + (type === 'error' ? ' error' : type === 'info' ? ' info' : '');
    el.innerHTML = (type === 'error' ? '⚠️ ' : type === 'info' ? 'ℹ️ ' : '✅ ') + esc(msg);
    wrap.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(function () { el.remove(); }, 320); }, 4200);
  }

  // کنش‌های فقط‌خواندنی: همیشه با GET ارسال می‌شوند تا CSRF لازم نباشد
  var READ_ACTIONS = {
    me: 1, bootstrap: 1, dashboard_stats: 1, tasks_list: 1, task_get: 1, users_list: 1,
    departments_list: 1, projects_list: 1, attendance_today: 1, attendance_list: 1,
    attendance_team: 1, leaves_list: 1, announcements_list: 1, notifications_list: 1,
    activity_list: 1, calendar_month: 1, assignable: 1, settings_get: 1, db_status: 1
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
    } else if (!isRead && payload && Object.keys(payload).length) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = window.APP.csrf;
      init.body = JSON.stringify(payload);
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
  function priorityBadge(pr, label) {
    var map = { urgent: 'فوری', high: 'زیاد', medium: 'متوسط', low: 'کم' };
    return '<span class="badge badge-' + esc(pr) + '">⚡ ' + esc(label || map[pr] || pr) + '</span>';
  }
  function dueBadge(t) {
    var b = t.due_badge || { text: 'بدون سررسید', state: 'none' };
    return '<span class="badge badge-' + esc(b.state) + '">📅 ' + esc(b.text) + (t.due_time_fa ? ' — ' + esc(t.due_time_fa) : '') + '</span>';
  }
  function prioDot(pr) { return '<span class="prio-dot prio-' + esc(pr) + '"></span>'; }

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
    dashboard: 'داشبورد', tasks: 'مدیریت تسک‌ها', calendar: 'تقویم شمسی',
    projects: 'پروژه‌ها', employees: 'کارمندان', departments: 'واحدهای سازمانی',
    attendance: 'حضور و غیاب', leaves: 'مرخصی‌ها', announcements: 'اطلاعیه‌ها',
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
      case 'dashboard': loadDashboard(); break;
      case 'tasks': loadTasks(); break;
      case 'calendar': if (!state.calendar) loadCalendar(); break;
      case 'projects': loadProjects(); break;
      case 'employees': loadEmployees(); break;
      case 'departments': loadDepartments(); break;
      case 'attendance': loadAttendance(); break;
      case 'leaves': loadLeaves(); break;
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
      renderAttendanceHead(res.attendance_today);
      renderMyTasks(res.my_tasks || []);
      renderUpcoming(res.my_tasks || []);
      renderChart7d(res.chart_7d || []);
      renderChartPriority(res.by_priority || {});
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
        '<div class="small">حاضر امروز: <strong>' + FAT(d.team_present_today || 0) + '</strong></div>' +
        '<div class="small">پروژه‌های فعال: <strong>' + FAT(d.project_count) + '</strong></div>' +
        '<div class="small">مرخصی در انتظار: <strong>' + FAT(d.pending_leaves) + '</strong></div></div>' : '');
    var box = $('#dashStats');
    if (APP.user.is_manager) {
      box.insertAdjacentHTML('beforeend', '');
    }
  }

  function renderAttendanceHead(att) {
    var el = $('#dashCheckIn');
    var inBtn = $('#btnClockIn'), outBtn = $('#btnClockOut');
    if (!att) {
      el.textContent = 'ثبت نشده';
      inBtn.style.display = '';
      outBtn.style.display = 'none';
    } else {
      el.textContent = att.check_in_fa || '—';
      inBtn.style.display = 'none';
      outBtn.style.display = att.check_out ? 'none' : '';
      if (att.check_out) {
        el.textContent = att.check_in_fa + ' → ' + att.check_out_fa;
      }
    }
  }

  function renderMyTasks(tasks) {
    var wrap = $('#dashMyTasks');
    if (!tasks.length) { wrap.innerHTML = emptyState('🎉', 'تسکی روی میز شما نیست!'); return; }
    wrap.innerHTML = tasks.slice(0, 6).map(function (t) {
      return '<div class="task-list-row" data-task="' + t.id + '">' +
        prioDot(t.priority) +
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
        '<div class="small muted">👤 ' + esc(t.assignee_name || '—') + (t.project_name ? ' • ' + esc(t.project_name) : '') + '</div></div>' +
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
  function renderChartPriority(bp) {
    var canvas = $('#chartPriority');
    if (!canvas) return;
    var p = prepCanvas(canvas), ctx = p.ctx, w = p.w, h = p.h;
    var text = cssVar('--text', '#0f172a'), muted = cssVar('--muted', '#64748b');
    ctx.clearRect(0, 0, w, h);
    var rows = [
      { key: 'urgent', label: 'فوری', color: '#dc2626' },
      { key: 'high', label: 'زیاد', color: '#ea580c' },
      { key: 'medium', label: 'متوسط', color: '#ca8a04' },
      { key: 'low', label: 'کم', color: '#6366f1' }
    ];
    var total = rows.reduce(function (s, r) { return s + (bp[r.key] || 0); }, 0);
    var padR = 10, padL = 74, top = 14, rowH = Math.min(46, (h - top - 10) / 4), barMax = w - padR - padL - 40;
    rows.forEach(function (r, i) {
      var y = top + i * rowH;
      var val = bp[r.key] || 0;
      var bw = total > 0 ? Math.max(val > 0 ? 8 : 0, (val / total) * barMax) : 0;
      ctx.fillStyle = text; ctx.font = '12px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
      ctx.fillText(r.label, w - padR, y + 18);
      roundRect(ctx, padR, y + 5, barMax, 16, 8); ctx.fillStyle = cssVar('--bg2', '#e2e8f0'); ctx.fill();
      if (bw > 0) { roundRect(ctx, padR + barMax - bw, y + 5, bw, 16, 8); ctx.fillStyle = r.color; ctx.fill(); }
      ctx.fillStyle = muted; ctx.font = '11px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
      ctx.fillText(FAT(val), padL - 8 + 24, y + 18);
      if (total > 0) {
        ctx.fillStyle = r.color; ctx.textAlign = 'left';
        ctx.fillText(FAT(Math.round(val * 100 / total)) + '٪', padR + barMax + 6, y + 18);
      }
    });
    if (total === 0) {
      ctx.fillStyle = muted; ctx.font = '13px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
      ctx.fillText('تسک باز با اولویت ثبت نشده است', w / 2, h / 2);
    }
  }
  function roundRect(ctx, x, y, w, h, r) {
    r = Math.min(r, Math.abs(w) / 2, Math.abs(h) / 2);
    if (h < 0) { y += h; h = -h; }
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }
  function rerenderCharts() {
    if (state.section === 'dashboard' && state.dashboard) {
      renderChart7d(state.dashboard.chart_7d || []);
      renderChartPriority(state.dashboard.by_priority || {});
    }
  }

  /* ================= تسک‌ها ================= */
  function taskQuery() {
    var q = { scope: $('#taskScope').value };
    var s = $('#taskSearch').value.trim();
    if (s) q.search = s;
    var st = $('#taskStatusFilter').value; if (st) q.status = st;
    var pr = $('#taskPriorityFilter').value; if (pr) q.priority = pr;
    var pj = $('#taskProjectFilter').value; if (pj) q.project_id = pj;
    var uf = $('#taskUserFilter'); if (uf && uf.value) q.assigned_to = uf.value;
    return q;
  }

  var searchTimer = null;
  function loadTasks() {
    $('#tasksListView').innerHTML = '<div class="loading"><div class="spinner"></div>در حال دریافت تسک‌ها...</div>';
    api('tasks_list', taskQuery()).then(function (res) {
      state.tasks = res.tasks || [];
      renderTasks();
    }).catch(function (err) {
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
        prioDot(t.priority) +
        '<div class="grow">' +
        '<h5>' + esc(t.title) + (t.created_via === 'voice_ai' ? ' <span title="ثبت با هوش مصنوعی">🎙️</span>' : '') + '</h5>' +
        '<div class="small muted">' + esc(t.task_code) + ' • ' + esc(t.assignee_name || '—') +
        (t.project_name ? ' • 🚀 ' + esc(t.project_name) : '') +
        (t.subtasks_total ? ' • ☑️ ' + FAT(t.subtasks_done) + '/' + FAT(t.subtasks_total) : '') + '</div></div>' +
        '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:flex-end">' +
        statusBadge(t.status) + priorityBadge(t.priority) + dueBadge(t) + '</div>' +
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
          if (state.section === 'dashboard') loadDashboard();
        }).catch(function (err) { toast(err.message, 'error'); renderKanban(); });
      });
    });
  }
  function kanbanCard(t) {
    return '<div class="task-card" data-task="' + t.id + '">' +
      '<div class="tc-top">' + priorityBadge(t.priority) + (t.project_name ? '<span class="badge badge-todo">🚀 ' + esc(t.project_name) + '</span>' : '') + '</div>' +
      '<h5>' + esc(t.title) + (t.created_via === 'voice_ai' ? ' 🎙️' : '') + '</h5>' +
      '<div class="tc-meta">' + dueBadge(t) + (t.subtasks_total ? '<span>☑️ ' + FAT(t.subtasks_done) + '/' + FAT(t.subtasks_total) + '</span>' : '') + '</div>' +
      (t.progress > 0 ? '<div class="progress" style="margin-top:8px"><div style="width:' + t.progress + '%"></div></div>' : '') +
      '<div class="tc-foot">' + avatar(t.assignee_name, t.assignee_color, 'avatar-sm') + '<span class="small muted">' + esc(t.assignee_name || '—') + '</span></div>' +
      '</div>';
  }

  function initTaskToolbar() {
    $('#taskSearch').addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(loadTasks, 380);
    });
    ['#taskScope', '#taskStatusFilter', '#taskPriorityFilter', '#taskProjectFilter', '#taskUserFilter'].forEach(function (s) {
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
    $('#tfProject').value = task && task.project_id ? String(task.project_id) : (prefill && prefill.project_id) || '';
    $('#tfStatus').value = task ? task.status : 'todo';
    $('#tfPriority').value = task ? task.priority : (prefill && prefill.priority) || 'medium';
    $('#tfDueDate').value = task && task.due_date ? J.gregorianToJalaliInput(task.due_date) : (prefill && prefill.due_date_jalali) || APP.today.jalali;
    $('#tfDueTime').value = task && task.due_time ? task.due_time : (prefill && prefill.due_time) || '';
    $('#tfStartDate').value = task && task.start_date ? J.gregorianToJalaliInput(task.start_date) : '';
    $('#tfEstimate').value = task ? (task.estimated_minutes || 60) : (prefill && prefill.estimated_minutes) || 60;
    $('#tfRecurrence').value = task ? (task.recurrence || 'none') : 'none';
    $('#tfCategory').value = task ? (task.category || '') : '';
    $('#tfTags').value = task ? (task.tags || '') : '';
    $('#tfSubtasks').value = '';
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
    $('#btnSuggestSubtasks').addEventListener('click', function () {
      var title = $('#tfTitle').value.trim();
      if (!title) { toast('ابتدا عنوان تسک را بنویسید.', 'error'); return; }
      this.disabled = true;
      var btn = this;
      api('voice_suggest_subtasks', { title: title, category: $('#tfCategory').value })
        .then(function (res) {
          if (res.subtasks && res.subtasks.length) {
            $('#tfSubtasks').value = res.subtasks.join('\n');
            toast('✨ ' + FAT(res.subtasks.length) + ' زیرتسک پیشنهادی اضافه شد.');
          } else toast('پیشنهادی پیدا نشد.', 'info');
        }).catch(function (e) { toast(e.message, 'error'); })
        .finally(function () { btn.disabled = false; });
    });
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
        project_id: $('#tfProject').value ? Number($('#tfProject').value) : null,
        status: $('#tfStatus').value,
        priority: $('#tfPriority').value,
        due_date: dueG || '',
        due_time: $('#tfDueTime').value,
        start_date: startG || '',
        estimated_minutes: Number($('#tfEstimate').value) || 0,
        recurrence: $('#tfRecurrence').value,
        category: $('#tfCategory').value.trim(),
        tags: $('#tfTags').value.trim(),
        subtasks: $('#tfSubtasks').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean)
      };
      if (!payload.title) { toast('عنوان تسک الزامی است.', 'error'); return; }
      var btn = $('#btnSaveTask');
      btn.disabled = true;
      var promise = id ? api('task_update', Object.assign({ id: Number(id) }, payload)) : api('task_create', payload);
      promise.then(function (res) {
        toast(res.message || 'تسک ذخیره شد.');
        closeTaskForm();
        if (state.section === 'tasks') loadTasks();
        if (state.section === 'calendar') loadCalendar(state.calYear, state.calMonth);
        if (state.section === 'dashboard') loadDashboard();
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
      var subsDone = t.subtasks_done || 0, subsTotal = t.subtasks_total || 0;
      var html = '' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">' +
        statusBadge(t.status, t.status_label) + priorityBadge(t.priority, t.priority_label) + dueBadge(t) +
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
        '<h4 style="font-size:14px">☑️ چک‌لیست (' + FAT(subsDone) + '/' + FAT(subsTotal) + ')</h4>' +
        '<div id="drawerSubtasks">' + (t.subtasks.length ? t.subtasks.map(function (s) {
          return '<div class="subtask-item' + (s.is_done ? ' done' : '') + '"><input type="checkbox" data-sub="' + s.id + '"' + (s.is_done ? ' checked' : '') + '><span class="grow">' + esc(s.title) + '</span><button class="icon-btn" data-subdel="' + s.id + '" title="حذف">✕</button></div>';
        }).join('') : '<div class="small muted">زیرتسکی ندارد.</div>') + '</div>' +
        '<div class="toolbar" style="margin-top:8px"><input type="text" id="newSubtaskInput" placeholder="افزودن زیرتسک..." style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--text)"><button class="btn btn-sm" data-act="sub-add">➕ افزودن</button></div>' +
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
            if (state.section === 'dashboard') loadDashboard();
          }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'archive') {
          api('task_update', { id: t.id, is_archived: t.is_archived ? 0 : 1 }).then(function () {
            toast(t.is_archived ? 'تسک بازگردانی شد.' : 'تسک بایگانی شد.');
            closeDrawer();
            if (state.section === 'tasks') loadTasks();
            if (state.section === 'dashboard') loadDashboard();
          }).catch(function (e) { toast(e.message, 'error'); });
        } else if (act === 'delete') {
          if (!confirm('آیا از حذف این تسک مطمئن هستید؟ این عملیات قابل بازگشت نیست.')) return;
          api('task_delete', { id: t.id }).then(function (r) {
            toast(r.message || 'تسک حذف شد.');
            closeDrawer();
            if (state.section === 'tasks') loadTasks();
            if (state.section === 'dashboard') loadDashboard();
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
  function loadCalendar(jy, jm) {
    var t = J.todayJalali();
    jy = jy || state.calYear || t[0];
    jm = jm || state.calMonth || t[1];
    state.calYear = jy; state.calMonth = jm;
    $('#calGrid').innerHTML = '<div class="loading" style="grid-column:1/-1"><div class="spinner"></div></div>';
    api('calendar_month', { jy: jy, jm: jm }).then(function (res) {
      state.calendar = res.calendar;
      renderCalendar(res.calendar);
    }).catch(function (err) {
      $('#calGrid').innerHTML = emptyState('⚠️', err.message);
    });
  }
  function renderCalendar(cal) {
    $('#calTitle').textContent = cal.title + ' — ' + (cal.season || '');
    $('#calWeekdays').innerHTML = J.WEEKDAY_NAMES.map(function (w, i) {
      return '<div class="cal-weekday' + (i === 6 ? ' fri' : '') + '">' + w + '</div>';
    }).join('');
    var cells = [];
    for (var i = 0; i < cal.start_weekday; i++) cells.push('<div class="cal-day empty"></div>');
    cal.days.forEach(function (d) {
      var tasks = d.tasks || [];
      cells.push('<div class="cal-day' + (d.is_today ? ' today' : '') + (d.is_friday ? ' friday' : '') + '" data-date="' + d.gregorian + '" data-jalali="' + d.jalali + '">' +
        '<div class="dnum">' + d.day_fa + (d.is_today ? '<span class="today-tag">امروز</span>' : '') + '</div>' +
        tasks.slice(0, 3).map(function (t) {
          return '<span class="cal-task st-' + esc(t.status) + '" data-task="' + t.id + '" title="' + esc(t.title + ' — ' + t.assignee_name) + '" style="background:' + hexAlpha(t.project_color, .14) + '">' +
            (t.due_time_fa ? '🕐 ' + esc(t.due_time_fa) + ' ' : '') + esc(t.title) + '</span>';
        }).join('') +
        (tasks.length > 3 ? '<div class="cal-more">+' + FAT(tasks.length - 3) + ' تسک دیگر</div>' : '') +
        '</div>');
    });
    var total = cal.days.length + cal.start_weekday;
    var pad = (7 - (total % 7)) % 7;
    for (var k = 0; k < pad; k++) cells.push('<div class="cal-day empty"></div>');
    $('#calGrid').innerHTML = cells.join('');
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

  /* ================= پروژه‌ها ================= */
  function loadProjects() {
    $('#projectsGrid').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    api('projects_list').then(function (res) {
      state.projects = res.projects || [];
      renderProjects();
    }).catch(function (err) { $('#projectsGrid').innerHTML = emptyState('⚠️', err.message); });
  }
  function renderProjects() {
    var grid = $('#projectsGrid');
    if (!state.projects.length) { grid.innerHTML = emptyState('🚀', 'پروژه‌ای ثبت نشده است.'); return; }
    grid.innerHTML = state.projects.map(function (p) {
      var st = { active: 'فعال', paused: 'متوقف', done: 'تکمیل‌شده', archived: 'بایگانی' }[p.status] || p.status;
      return '<div class="card" style="border-top:4px solid ' + esc(p.color || '#0284c7') + '">' +
        '<div style="display:flex;gap:8px;align-items:flex-start"><h3 style="flex:1;font-size:15px">' + esc(p.name) + '</h3><span class="badge badge-' + esc(p.status) + '">' + esc(st) + '</span></div>' +
        '<div class="small muted" style="margin-bottom:8px">کد ' + esc(p.code) + (p.department_name ? ' • 🏢 ' + esc(p.department_name) : '') + '</div>' +
        (p.description ? '<p class="small" style="margin:0 0 10px;color:var(--muted)">' + esc(p.description).slice(0, 140) + '</p>' : '') +
        '<div class="progress" style="margin-bottom:6px"><div style="width:' + (p.progress || 0) + '%"></div></div>' +
        '<div class="small muted" style="display:flex;justify-content:space-between;margin-bottom:8px"><span>تیک‌ها: ' + FAT(p.tasks_done) + '/' + FAT(p.tasks_total) + '</span><span>' + FAT(p.progress || 0) + '٪</span></div>' +
        '<div class="small muted">📅 مهلت: ' + esc(p.due_human || '—') + '</div>' +
        (p.owner_name ? '<div class="small muted">👤 مسئول: ' + esc(p.owner_name) + '</div>' : '') +
        '<div class="toolbar" style="margin-top:10px;margin-bottom:0">' +
        '<button class="btn btn-sm" data-proj-tasks="' + p.id + '">🗂️ تسک‌ها</button>' +
        (APP.user.is_manager ? '<button class="btn btn-sm" data-proj-edit="' + p.id + '">✏️ ویرایش</button><button class="btn btn-sm btn-danger" data-proj-del="' + p.id + '">🗑️</button>' : '') +
        '</div></div>';
    }).join('');
    $$('[data-proj-tasks]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = this.getAttribute('data-proj-tasks');
        $('#taskProjectFilter').value = id;
        go('tasks');
      });
    });
    $$('[data-proj-edit]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = state.projects.filter(function (x) { return x.id === Number(this.getAttribute('data-proj-edit')); }.bind(this))[0];
        projectModal(p);
      });
    });
    $$('[data-proj-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('پروژه حذف شود؟')) return;
        api('project_delete', { id: Number(this.getAttribute('data-proj-del')) }).then(function (r) { toast(r.message || 'حذف شد.'); loadProjects(); }).catch(function (e) { toast(e.message, 'error'); });
      });
    });
  }

  function projectModal(project) {
    var p = project || {};
    openGeneric(project ? '✏️ ویرایش پروژه' : '🚀 پروژه جدید', '' +
      '<label class="field"><span>نام پروژه *</span><input type="text" id="pjName" value="' + esc(p.name || '') + '"></label>' +
      '<label class="field"><span>توضیحات</span><textarea id="pjDesc" rows="2">' + esc(p.description || '') + '</textarea></label>' +
      '<div class="form-row"><label class="field"><span>کد پروژه</span><input type="text" id="pjCode" value="' + esc(p.code || '') + '" ' + (project ? 'disabled' : '') + '></label>' +
      '<label class="field"><span>رنگ</span><input type="color" id="pjColor" value="' + esc(p.color || '#0284c7') + '" style="height:42px;padding:4px"></label></div>' +
      '<div class="form-row"><label class="field"><span>واحد</span><select id="pjDept"><option value="">—</option>' + APP.departments.map(function (d) { return '<option value="' + d.id + '"' + (p.department_id === d.id ? ' selected' : '') + '>' + esc(d.name) + '</option>'; }).join('') + '</select></label>' +
      '<label class="field"><span>وضعیت</span><select id="pjStatus">' + [['active', 'فعال'], ['paused', 'متوقف'], ['done', 'تکمیل‌شده'], ['archived', 'بایگانی']].map(function (x) { return '<option value="' + x[0] + '"' + (p.status === x[0] ? ' selected' : '') + '>' + x[1] + '</option>'; }).join('') + '</select></label></div>' +
      '<div class="form-row"><label class="field"><span>تاریخ شروع (شمسی)</span><input type="text" id="pjStart" dir="ltr" value="' + esc(p.start_date ? J.gregorianToJalaliInput(p.start_date) : APP.today.jalali) + '"></label>' +
      '<label class="field"><span>مهلت پایان (شمسی)</span><input type="text" id="pjDue" dir="ltr" value="' + esc(p.due_date ? J.gregorianToJalaliInput(p.due_date) : '') + '"></label></div>' +
      '<button class="btn btn-primary btn-block" id="pjSave">💾 ذخیره پروژه</button>');
    $('#pjSave').addEventListener('click', function () {
      var due = $('#pjDue').value.trim();
      var dueG = due ? J.parseToGregorian(due) : null;
      if (due && !dueG) { toast('تاریخ مهلت نامعتبر است.', 'error'); return; }
      var payload = {
        name: $('#pjName').value.trim(), description: $('#pjDesc').value.trim(),
        color: $('#pjColor').value, department_id: $('#pjDept').value || null,
        status: $('#pjStatus').value, start_date: J.parseToGregorian($('#pjStart').value.trim()) || '',
        due_date: dueG || ''
      };
      if (!payload.name) { toast('نام پروژه الزامی است.', 'error'); return; }
      if (!project) payload.code = $('#pjCode').value.trim();
      else payload.id = project.id;
      api(project ? 'project_update' : 'project_create', payload).then(function (r) {
        toast(r.message || 'ذخیره شد.');
        closeGeneric();
        loadProjects();
      }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  /* ================= کارمندان ================= */
  function loadEmployees() {
    api('users_list').then(function (res) {
      state.users = res.users || [];
      renderEmployees();
    }).catch(function (err) {
      $('#employeesBody').innerHTML = '<tr><td colspan="9" class="center muted">' + esc(err.message) + '</td></tr>';
    });
  }
  function renderEmployees() {
    var q = ($('#empSearch').value || '').trim();
    var rows = state.users.filter(function (u) {
      if (!q) return true;
      var hay = (u.full_name + ' ' + u.employee_code + ' ' + (u.position_title || '') + ' ' + (u.department_name || '') + ' ' + u.username).toLowerCase();
      return hay.indexOf(q.toLowerCase()) !== -1;
    });
    var body = $('#employeesBody');
    if (!rows.length) { body.innerHTML = '<tr><td colspan="9" class="center muted">کارمندی یافت نشد.</td></tr>'; return; }
    body.innerHTML = rows.map(function (u) {
      return '<tr>' +
        '<td><div style="display:flex;gap:8px;align-items:center">' + avatar(u.full_name, u.avatar_color, 'avatar-sm') + '<div><div style="font-weight:700">' + esc(u.full_name) + '</div><div class="small muted" dir="ltr">' + esc(u.username) + '</div></div></div></td>' +
        '<td><code>' + esc(u.employee_code) + '</code></td>' +
        '<td>' + esc(u.position_title || '—') + '</td>' +
        '<td>' + esc(u.department_name || '—') + '</td>' +
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
      '<label class="field"><span>تلفن</span><input type="text" id="emPhone" dir="ltr" value="' + esc(u.phone || '') + '"></label></div>' +
      '<div class="form-row"><label class="field"><span>سمت سازمانی</span><input type="text" id="emPosition" value="' + esc(u.position_title || '') + '" placeholder="مثلاً کارشناس فروش"></label>' +
      '<label class="field"><span>واحد سازمانی</span><select id="emDept"><option value="">—</option>' + APP.departments.map(function (d) { return '<option value="' + d.id + '"' + (u.department_id === d.id ? ' selected' : '') + '>' + esc(d.name) + '</option>'; }).join('') + '</select></label></div>' +
      '<div class="form-row"><label class="field"><span>نقش سیستمی</span><select id="emRole">' +
      '<option value="employee"' + (u.role === 'employee' ? ' selected' : '') + '>کارمند</option>' +
      '<option value="manager"' + (u.role === 'manager' ? ' selected' : '') + '>سرپرست واحد</option>' +
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
        department_id: $('#emDept').value || null,
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

  /* ================= واحدها ================= */
  function loadDepartments() {
    api('departments_list').then(function (res) {
      state.departments = res.departments || [];
      APP.departments = state.departments.map(function (d) { return { id: d.id, name: d.name }; });
      var grid = $('#departmentsGrid');
      if (!state.departments.length) { grid.innerHTML = emptyState('🏢', 'واحدی ثبت نشده است.'); return; }
      grid.innerHTML = state.departments.map(function (d) {
        return '<div class="card" style="border-right:5px solid ' + esc(d.color || '#0f766e') + '">' +
          '<h3 style="font-size:15px">' + esc(d.name) + '</h3>' +
          (d.description ? '<p class="small muted">' + esc(d.description) + '</p>' : '') +
          '<div class="small">👥 اعضا: <strong>' + FAT(d.members_count) + '</strong></div>' +
          '<div class="small">🚀 پروژه‌های فعال: <strong>' + FAT(d.projects_count) + '</strong></div>' +
          '<div class="small">👤 سرپرست: <strong>' + esc(d.manager_name || '—') + '</strong></div>' +
          '<div class="toolbar" style="margin-top:10px;margin-bottom:0"><button class="btn btn-sm" data-dept-edit="' + d.id + '">✏️ ویرایش</button>' +
          '<button class="btn btn-sm btn-danger" data-dept-del="' + d.id + '">🗑️</button></div></div>';
      }).join('');
      $$('[data-dept-edit]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = Number(this.getAttribute('data-dept-edit'));
          departmentModal(state.departments.filter(function (d) { return d.id === id; })[0]);
        });
      });
      $$('[data-dept-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('واحد حذف شود؟')) return;
          api('department_delete', { id: Number(this.getAttribute('data-dept-del')) }).then(function (r) { toast(r.message || 'حذف شد.'); loadDepartments(); }).catch(function (e) { toast(e.message, 'error'); });
        });
      });
    }).catch(function (err) { toast(err.message, 'error'); });
  }
  function departmentModal(dept) {
    var d = dept || {};
    openGeneric(dept ? '✏️ ویرایش واحد' : '🏢 واحد جدید', '' +
      '<label class="field"><span>نام واحد *</span><input type="text" id="dpName" value="' + esc(d.name || '') + '"></label>' +
      '<label class="field"><span>توضیحات</span><input type="text" id="dpDesc" value="' + esc(d.description || '') + '"></label>' +
      '<div class="form-row"><label class="field"><span>رنگ</span><input type="color" id="dpColor" value="' + esc(d.color || '#0f766e') + '" style="height:42px;padding:4px"></label>' +
      '<label class="field"><span>سرپرست</span><select id="dpManager"><option value="">—</option>' + APP.users.map(function (u) { return '<option value="' + u.id + '"' + (d.manager_id === u.id ? ' selected' : '') + '>' + esc(u.full_name) + '</option>'; }).join('') + '</select></label></div>' +
      '<button class="btn btn-primary btn-block" id="dpSave">💾 ذخیره</button>');
    $('#dpSave').addEventListener('click', function () {
      var payload = { name: $('#dpName').value.trim(), description: $('#dpDesc').value.trim(), color: $('#dpColor').value, manager_id: $('#dpManager').value || null };
      if (!payload.name) { toast('نام واحد الزامی است.', 'error'); return; }
      if (dept) payload.id = dept.id;
      api(dept ? 'department_update' : 'department_create', payload).then(function (r) {
        toast(r.message || 'ذخیره شد.');
        closeGeneric();
        loadDepartments();
      }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  /* ================= حضور و غیاب ================= */
  function loadAttendance() {
    if (!state.attInitDone) {
      var t = J.todayJalali();
      $('#attMonth').innerHTML = J.MONTH_NAMES.map(function (m, i) { return '<option value="' + (i + 1) + '"' + (i + 1 === t[1] ? ' selected' : '') + '>' + m + '</option>'; }).join('');
      $('#attYear').value = t[0];
      state.attInitDone = true;
    }
    api('attendance_today').then(function (res) {
      var a = res.attendance;
      $('#attCheckIn').textContent = a ? a.check_in_fa : '—';
      $('#attCheckOut').textContent = a && a.check_out ? a.check_out_fa : '—';
      $('#attTotal').textContent = a ? 'مدت حضور: ' + a.total_fa + ' • ' + a.work_mode_label : 'امروز هنوز ثبت نشده است.';
      if (a) $('#attWorkMode').value = a.work_mode;
    }).catch(function () {});
    loadAttendanceList();
    if (APP.user.is_manager) loadAttendanceTeam();
  }
  function loadAttendanceList() {
    var body = $('#attendanceBody');
    body.innerHTML = '<tr><td colspan="6"><div class="loading"><div class="spinner"></div></div></td></tr>';
    var q = { jy: Number($('#attYear').value), jm: Number($('#attMonth').value) };
    var u = $('#attUser');
    if (u && u.value) q.user_id = u.value;
    api('attendance_list', q).then(function (res) {
      $('#attDaysPresent').textContent = FAT(res.days_present);
      $('#attMonthTotal').textContent = res.total_fa;
      if (!res.rows.length) {
        body.innerHTML = '<tr><td colspan="6" class="center muted">سابقه‌ای برای این ماه ثبت نشده است.</td></tr>';
        return;
      }
      body.innerHTML = res.rows.map(function (r) {
        return '<tr><td>' + esc(r.work_date_jalali) + '</td><td class="small">' + esc(r.work_date_human.split(' ')[0]) + '</td>' +
          '<td>' + esc(r.check_in_fa) + '</td><td>' + esc(r.check_out_fa) + '</td><td>' + esc(r.total_fa) + '</td><td class="small">' + esc(r.work_mode_label) + '</td></tr>';
      }).join('');
    }).catch(function (err) {
      body.innerHTML = '<tr><td colspan="6" class="center muted">' + esc(err.message) + '</td></tr>';
    });
  }
  function loadAttendanceTeam() {
    var wrap = $('#attTeamList');
    if (!wrap) return;
    api('attendance_team').then(function (res) {
      $('#attTeamDate').textContent = '— ' + res.date_human;
      if (!res.team.length) { wrap.innerHTML = emptyState('👥', 'کارمندی نیست.'); return; }
      wrap.innerHTML = res.team.map(function (r) {
        return '<div class="team-row">' + avatar(r.full_name, r.avatar_color, 'avatar-sm') +
          '<div class="grow"><div style="font-weight:700;font-size:13px">' + esc(r.full_name) + '</div><div class="small muted">' + esc(r.position_title || '') + ' • ' + esc(r.department_name || '—') + '</div></div>' +
          (r.present ? '<span class="badge badge-active">حاضر ' + esc(r.check_in_fa) + '</span>' : '<span class="badge badge-archived">غایب</span>') +
          (r.check_out ? '<span class="small muted">خروج ' + esc(r.check_out_fa) + ' • ' + esc(r.total_fa) + '</span>' : '') +
          '</div>';
      }).join('');
    }).catch(function (err) { wrap.innerHTML = emptyStr(err.message); });
  }
  function emptyStr(t) { return '<div class="small muted">' + esc(t) + '</div>'; }

  function clockIn() {
    var mode = $('#attWorkMode') ? $('#attWorkMode').value : 'office';
    api('attendance_clock_in', { work_mode: mode }).then(function (r) {
      toast(r.message || 'ورود ثبت شد.');
      if (state.section === 'attendance') loadAttendance();
      if (state.section === 'dashboard') loadDashboard();
    }).catch(function (e) { toast(e.message, 'error'); });
  }
  function clockOut() {
    api('attendance_clock_out').then(function (r) {
      toast(r.message || 'خروج ثبت شد.');
      if (state.section === 'attendance') loadAttendance();
      if (state.section === 'dashboard') loadDashboard();
    }).catch(function (e) { toast(e.message, 'error'); });
  }

  function attendanceManualModal() {
    var users = APP.users.map(function (u) { return '<option value="' + u.id + '">' + esc(u.full_name) + '</option>'; }).join('');
    openGeneric('📝 ثبت دستی حضور و غیاب', '' +
      '<div class="alert alert-info small">برای کارمندانی که ورود/خروج را فراموش کرده‌اند یا سابقه گذشته نیاز به اصلاح دارد. اگر سابقه همان روز موجود باشد، به‌روزرسانی می‌شود.</div>' +
      '<label class="field"><span>کارمند *</span><select id="amUser">' + users + '</select></label>' +
      '<div class="form-row"><label class="field"><span>تاریخ (شمسی) *</span><input type="text" id="amDate" dir="ltr" value="' + APP.today.jalali + '"></label>' +
      '<label class="field"><span>نوع حضور</span><select id="amMode"><option value="office">حضوری در شرکت</option><option value="remote">دورکاری</option><option value="mission">مأموریت</option></select></label></div>' +
      '<div class="form-row"><label class="field"><span>ساعت ورود *</span><input type="time" id="amIn" value="08:30"></label>' +
      '<label class="field"><span>ساعت خروج</span><input type="time" id="amOut" value="17:00"></label></div>' +
      '<label class="field"><span>یادداشت</span><input type="text" id="amNote" placeholder="مثلاً: اصلاح سابقه فراموش‌شده"></label>' +
      '<button class="btn btn-primary btn-block" id="amSave">💾 ثبت حضور</button>');
    $('#amSave').addEventListener('click', function () {
      var dateVal = $('#amDate').value.trim();
      var greg = J.parseToGregorian(dateVal);
      if (!greg) { toast('تاریخ شمسی نامعتبر است.', 'error'); return; }
      api('attendance_manual', {
        user_id: Number($('#amUser').value), work_date: greg,
        check_in: $('#amIn').value, check_out: $('#amOut').value,
        work_mode: $('#amMode').value, note: $('#amNote').value.trim()
      }).then(function (r) {
        toast(r.message || 'ثبت شد.');
        closeGeneric();
        loadAttendance();
      }).catch(function (e) { toast(e.message, 'error'); });
    });
  }

  /* ================= مرخصی‌ها ================= */
  function loadLeaves() {
    $('#leavesBody').innerHTML = '<tr><td colspan="8"><div class="loading"><div class="spinner"></div></div></td></tr>';
    api('leaves_list').then(function (res) {
      if (!res.leaves.length) { $('#leavesBody').innerHTML = '<tr><td colspan="8" class="center muted">درخواست مرخصی ثبت نشده است.</td></tr>'; return; }
      $('#leavesBody').innerHTML = res.leaves.map(function (l) {
        return '<tr>' +
          '<td><div style="display:flex;gap:7px;align-items:center">' + avatar(l.user_name, l.avatar_color, 'avatar-sm') + '<span>' + esc(l.user_name) + '</span></div></td>' +
          '<td>' + esc(l.type_label) + '</td><td>' + esc(l.start_jalali) + '</td><td>' + esc(l.end_jalali) + '</td>' +
          '<td>' + (Number(l.hours) > 0 ? FAT(l.hours) : '—') + '</td>' +
          '<td class="small" style="max-width:200px">' + esc(l.reason).slice(0, 60) + '</td>' +
          '<td><span class="badge badge-' + esc(l.status) + '">' + esc(l.status_label) + '</span></td>' +
          '<td>' + (APP.user.is_manager && l.status === 'pending' ?
            '<div class="row-actions"><button class="btn btn-sm" data-leave-ok="' + l.id + '">✅ تأیید</button><button class="btn btn-sm btn-danger" data-leave-no="' + l.id + '">❌ رد</button></div>' : '—') + '</td></tr>';
      }).join('');
      $$('[data-leave-ok]').forEach(function (b) {
        b.addEventListener('click', function () { reviewLeave(Number(this.getAttribute('data-leave-ok')), 'approved'); });
      });
      $$('[data-leave-no]').forEach(function (b) {
        b.addEventListener('click', function () { reviewLeave(Number(this.getAttribute('data-leave-no')), 'rejected'); });
      });
    }).catch(function (err) { $('#leavesBody').innerHTML = '<tr><td colspan="8" class="center muted">' + esc(err.message) + '</td></tr>'; });
  }
  function reviewLeave(id, status) {
    var note = status === 'rejected' ? (prompt('دلیل رد مرخصی (اختیاری):') || '') : '';
    api('leave_review', { id: id, status: status, review_note: note }).then(function (r) {
      toast(r.message || 'بررسی ثبت شد.');
      loadLeaves();
    }).catch(function (e) { toast(e.message, 'error'); });
  }
  function leaveModal() {
    openGeneric('🌴 درخواست مرخصی', '' +
      '<label class="field"><span>نوع مرخصی</span><select id="lvType"><option value="daily">روزانه</option><option value="hourly">ساعتی</option><option value="sick">استعلاجی</option><option value="mission">مأموریت</option></select></label>' +
      '<div class="form-row"><label class="field"><span>از تاریخ (شمسی) *</span><input type="text" id="lvStart" dir="ltr" value="' + APP.today.jalali + '"></label>' +
      '<label class="field"><span>تا تاریخ (شمسی) *</span><input type="text" id="lvEnd" dir="ltr" value="' + APP.today.jalali + '"></label></div>' +
      '<label class="field"><span>تعداد ساعت (برای مرخصی ساعتی)</span><input type="number" id="lvHours" min="0" step="0.5" value="0"></label>' +
      '<label class="field"><span>دلیل *</span><textarea id="lvReason" rows="3" placeholder="دلیل درخواست مرخصی..."></textarea></label>' +
      '<button class="btn btn-primary btn-block" id="lvSave">📤 ارسال درخواست</button>');
    $('#lvSave').addEventListener('click', function () {
      var s = J.parseToGregorian($('#lvStart').value.trim());
      var e = J.parseToGregorian($('#lvEnd').value.trim());
      if (!s || !e) { toast('تاریخ شمسی نامعتبر است.', 'error'); return; }
      var reason = $('#lvReason').value.trim();
      if (!reason) { toast('دلیل مرخصی را بنویسید.', 'error'); return; }
      api('leave_create', {
        leave_type: $('#lvType').value, start_date: s, end_date: e,
        hours: $('#lvHours').value, reason: reason
      }).then(function (r) {
        toast(r.message || 'ثبت شد.');
        closeGeneric();
        loadLeaves();
      }).catch(function (err) { toast(err.message, 'error'); });
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
      '<label class="field"><span>واحد هدف</span><select id="anDept"><option value="">همه کارمندان</option>' + APP.departments.map(function (d) { return '<option value="' + d.id + '">' + esc(d.name) + '</option>'; }).join('') + '</select></label></div>' +
      '<label class="field"><span>معتبر تا تاریخ (شمسی، اختیاری)</span><input type="text" id="anExpires" dir="ltr" placeholder="۱۴۰۵/۱۲/۲۹"></label>' +
      '<div class="checkline"><input type="checkbox" id="anPinned"><label for="anPinned">سنجاق شود در بالای لیست</label></div>' +
      '<button class="btn btn-primary btn-block" id="anSave">📢 انتشار اطلاعیه</button>');
    $('#anSave').addEventListener('click', function () {
      var exp = $('#anExpires').value.trim();
      var expG = exp ? J.parseToGregorian(exp) : null;
      if (exp && !expG) { toast('تاریخ انقضا نامعتبر است.', 'error'); return; }
      api('announcement_create', {
        title: $('#anTitle').value.trim(), body: $('#anBody').value.trim(),
        priority: $('#anPrio').value, department_id: $('#anDept').value || '',
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
    api('notifications_list').then(function (res) {
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
          markNotification(Number(this.getAttribute('data-notif')));
          var type = this.getAttribute('data-notif-type');
          var rel = this.getAttribute('data-notif-rel');
          if (type === 'task' && rel) { go('tasks'); openTask(Number(rel)); }
          else if (type === 'leave') { go('leaves'); }
          else { loadNotifications(); }
          this.classList.remove('unread');
        });
      });
    }).catch(function (err) { $('#notificationsList').innerHTML = emptyState('⚠️', err.message); });
  }
  function markNotification(id) {
    api('notification_read', { id: id }).then(refreshNotifCount).catch(function () {});
  }
  function refreshNotifCount() {
    api('notifications_list').then(function (res) {
      var unread = res.notifications.filter(function (n) { return !n.is_read; }).length;
      var dot = $('#notifDot');
      dot.textContent = FAT(unread);
      dot.style.display = unread > 0 ? '' : 'none';
    }).catch(function () {});
  }

  /* ================= فعالیت‌ها ================= */
  function loadActivity() {
    $('#activityBody').innerHTML = '<tr><td colspan="4"><div class="loading"><div class="spinner"></div></div></td></tr>';
    api('activity_list').then(function (res) {
      if (!res.logs.length) { $('#activityBody').innerHTML = '<tr><td colspan="4" class="center muted">فعالیتی ثبت نشده است.</td></tr>'; return; }
      var icons = { create_task: '➕', update_task: '✏️', delete_task: '🗑️', comment: '💬', attach: '📎', clock_in: '⏰', clock_out: '🚪', leave_request: '🌴', leave_review: '✅', create_user: '👥', update_user: '👤', delete_user: '❌', create_project: '🚀', create_department: '🏢', announcement: '📢', voice_preview: '🎙️', settings: '⚙️', password_change: '🔑' };
      $('#activityBody').innerHTML = res.logs.map(function (l) {
        return '<tr><td>' + esc(l.user_name || 'سیستم') + '</td><td>' + (icons[l.action] || '•') + ' <code class="small">' + esc(l.action) + '</code></td>' +
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
      $('#stGroqKey').value = s.groq_api_key || '';
      $('#stGeminiKey').value = s.gemini_api_key || '';
      $('#stSelfTask').checked = s.allow_employee_self_task === '1';
      $('#stPeerTask').checked = s.allow_employee_peer_task === '1';
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
  }
  function initSettingsSave() {
    var btn = $('#btnSaveSettings');
    if (!btn) return;
    btn.addEventListener('click', function () {
      api('settings_save', {
        company_name: $('#stCompanyName').value.trim(),
        company_slogan: $('#stSlogan').value.trim(),
        work_start_time: $('#stWorkStart').value,
        work_end_time: $('#stWorkEnd').value,
        ai_provider: $('#stAiProvider').value,
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
    $('#btnClockIn').addEventListener('click', clockIn);
    $('#btnClockOut').addEventListener('click', clockOut);
    $('#btnAttClockIn').addEventListener('click', clockIn);
    $('#btnAttClockOut').addEventListener('click', clockOut);
    $('#btnAttLoad').addEventListener('click', loadAttendanceList);
    $('#attMonth').addEventListener('change', loadAttendanceList);
    $('#attYear').addEventListener('change', loadAttendanceList);
    var au = $('#attUser'); if (au) au.addEventListener('change', loadAttendanceList);
    $('#btnAttExport').addEventListener('click', function () {
      location.href = 'export.php?type=attendance&format=csv&jy=' + $('#attYear').value + '&jm=' + $('#attMonth').value;
    });
    $('#btnNewLeave').addEventListener('click', leaveModal);
    var attManual = $('#btnAttManual');
    if (attManual) attManual.addEventListener('click', attendanceManualModal);
    $('#btnFullscreen').addEventListener('click', function (e) {
      e.preventDefault();
      var doc = document.documentElement;
      if (!document.fullscreenElement) {
        (doc.requestFullscreen || doc.webkitRequestFullscreen || function () {}).call(doc);
      } else {
        (document.exitFullscreen || document.webkitExitFullscreen || function () {}).call(document);
      }
    });
    $('#btnNewProject') && $('#btnNewProject').addEventListener('click', function () { projectModal(null); });
    $('#btnNewEmployee') && $('#btnNewEmployee').addEventListener('click', function () { employeeModal(null); });
    $('#btnNewDepartment') && $('#btnNewDepartment').addEventListener('click', function () { departmentModal(null); });
    $('#btnNewAnnouncement') && $('#btnNewAnnouncement').addEventListener('click', announcementModal);
    $('#btnReadAll').addEventListener('click', function () {
      api('notifications_read_all').then(function (r) { toast(r.message || 'خوانده شد.'); loadNotifications(); refreshNotifCount(); }).catch(function (e) { toast(e.message, 'error'); });
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
    initNav();
    initTaskToolbar();
    initTaskForm();
    initDrawer();
    initCalendar();
    initProfile();
    initSettingsSave();
    initShortcuts();
    initGlobalButtons();

    VoiceAI.init({
      api: api,
      users: APP.users,
      projects: APP.projects,
      autoCreate: false,
      onCreated: function (res) {
        var count = (res.tasks || res.ids || []).length;
        toast('🎉 ' + FAT(count) + ' تسک صوتی در تقویم شمسی ثبت شد.', 'success');
        if (state.section === 'tasks') loadTasks();
        if (state.section === 'dashboard') loadDashboard();
        if (state.section === 'calendar') loadCalendar(state.calYear, state.calMonth);
      },
      onManual: function () { openTaskForm(null); }
    });
    // اگر کاربر آواتار برای مدال صوتی لازم بود
    refreshNotifCount();
  });
})();
