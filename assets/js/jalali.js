/* موتور تقویم هجری شمسی در سمت مرورگر — تبدیل میلادی <-> شمسی، اعداد فارسی */
(function (global) {
  'use strict';

  var MONTH_NAMES = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  var WEEKDAY_NAMES = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];

  function toFa(input) {
    var map = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return String(input == null ? '' : input).replace(/[0-9]/g, function (d) { return map[Number(d)]; });
  }
  function toEn(input) {
    var fa = '۰۱۲۳۴۵۶۷۸۹', ar = '٠١٢٣٤٥٦٧٨٩';
    return String(input == null ? '' : input).split('').map(function (ch) {
      var i = fa.indexOf(ch); if (i >= 0) return String(i);
      i = ar.indexOf(ch); if (i >= 0) return String(i);
      return ch;
    }).join('');
  }

  function toJalali(gy, gm, gd) {
    var gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
    var gy2 = gm > 2 ? gy + 1 : gy;
    var days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + (gdm[gm - 1] || 0);
    var jy = -1595 + 33 * Math.floor(days / 12053);
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    var jm, jd;
    if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
    else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
    return [jy, jm, jd];
  }

  function toGregorian(jy, jm, jd) {
    jy += 1595;
    var days = -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    var gy = 400 * Math.floor(days / 146097);
    days %= 146097;
    if (days > 36524) {
      gy += 100 * Math.floor(--days / 36524);
      days %= 36524;
      if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    var gd = days + 1;
    var leap = (gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0);
    var md = [0,31,leap ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
    var gm = 1;
    while (gm <= 12 && gd > md[gm]) { gd -= md[gm]; gm++; }
    return [gy, gm, gd];
  }

  function isLeap(jy) {
    var g = toGregorian(jy, 12, 30);
    var back = toJalali(g[0], g[1], g[2]);
    return back[0] === jy && back[1] === 12 && back[2] === 30;
  }
  function daysInMonth(jy, jm) {
    if (jm >= 1 && jm <= 6) return 31;
    if (jm >= 7 && jm <= 11) return 30;
    if (jm === 12) return isLeap(jy) ? 30 : 29;
    return 30;
  }
  function weekdayIndex(date) {
    var d = date instanceof Date ? date : new Date(date);
    return (d.getDay() + 1) % 7; // ۰=شنبه ... ۶=جمعه
  }
  function todayJalali() {
    var n = new Date();
    return toJalali(n.getFullYear(), n.getMonth() + 1, n.getDate());
  }
  function jalaliToGregorianString(jy, jm, jd) {
    var g = toGregorian(jy, jm, jd);
    return g[0] + '-' + String(g[1]).padStart(2, '0') + '-' + String(g[2]).padStart(2, '0');
  }
  function gregorianToJalaliString(iso) {
    var d = new Date(iso + 'T12:00:00');
    if (isNaN(d.getTime())) return '';
    var j = toJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
    return j[0] + '/' + String(j[1]).padStart(2, '0') + '/' + String(j[2]).padStart(2, '0');
  }
  function human(iso, withWeekday, withYear) {
    var d = new Date(iso + 'T12:00:00');
    if (isNaN(d.getTime())) return 'بدون تاریخ';
    var j = toJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
    var parts = [];
    if (withWeekday !== false) parts.push(WEEKDAY_NAMES[weekdayIndex(d)]);
    parts.push(toFa(j[2])); parts.push(MONTH_NAMES[j[1] - 1]);
    if (withYear !== false) parts.push(toFa(j[0]));
    return parts.join(' ');
  }
  // تبدیل ورودی شمسی کاربر (۱۴۰۵/۰۹/۰۱) به میلادی YYYY-MM-DD برای ارسال به سرور
  function parseToGregorian(input) {
    if (!input) return null;
    var clean = toEn(String(input)).trim();
    var m = clean.match(/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/);
    if (!m) return null;
    var y = +m[1], mo = +m[2], d = +m[3];
    if (y >= 1300 && y <= 1600) {
      if (mo < 1 || mo > 12 || d < 1 || d > daysInMonth(y, mo)) return null;
      return jalaliToGregorianString(y, mo, d);
    }
    if (y >= 1950 && y <= 2200) {
      var date = new Date(y, mo - 1, d, 12);
      if (date.getFullYear() !== y || date.getMonth() !== mo - 1 || date.getDate() !== d) return null;
      return y + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }
    return null;
  }
  // تبدیل میلادی به شمسی برای نمایش در input تاریخ
  function gregorianToJalaliInput(iso) {
    if (!iso) return '';
    return gregorianToJalaliString(iso);
  }

  // تقویم انتخاب تاریخ مستقل از کتابخانه یا سرویس خارجی؛ ورودی دستی نیز حفظ می‌شود.
  var activePicker = null;
  function closeDatePicker(restoreFocus) {
    if (!activePicker) return;
    var old = activePicker;
    if (restoreFocus) old.button.focus();
    old.panel.hidden = true;
    old.button.setAttribute('aria-expanded', 'false');
    activePicker = null;
  }
  function appToday() {
    var raw = global.APP && global.APP.today && global.APP.today.gregorian;
    if (raw && /^\d{4}-\d{2}-\d{2}$/.test(raw)) {
      var parts = raw.split('-').map(Number);
      return toJalali(parts[0], parts[1], parts[2]);
    }
    return todayJalali();
  }
  function attachDatePicker(input) {
    if (!input || input.dataset.jalaliPicker) return;
    input.dataset.jalaliPicker = 'true';
    var row = document.createElement('div'); row.className = 'date-picker-input';
    input.parentNode.insertBefore(row, input); row.appendChild(input);
    var button = document.createElement('button');
    button.type = 'button'; button.className = 'date-picker-toggle'; button.textContent = '📅';
    button.setAttribute('aria-label', 'انتخاب تاریخ از تقویم شمسی'); button.setAttribute('aria-expanded', 'false');
    row.appendChild(button);
    var panel = document.createElement('div'); panel.className = 'jalali-picker'; panel.hidden = true;
    panel.setAttribute('role', 'group'); panel.setAttribute('aria-label', 'انتخاب تاریخ شمسی');
    row.parentNode.insertBefore(panel, row.nextSibling);
    var viewYear, viewMonth;
    function setValue(value) {
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function render() {
      var today = appToday(), selected = parseToGregorian(input.value), parts = selected ? toJalali.apply(null, selected.split('-').map(Number)) : null;
      var first = toGregorian(viewYear, viewMonth, 1);
      var offset = weekdayIndex(new Date(first[0], first[1] - 1, first[2], 12));
      var html = '<div class="jalali-picker-head"><button type="button" data-nav="prev" aria-label="ماه قبل">‹</button><div class="jalali-picker-selects"><select aria-label="ماه شمسی" data-select="month">';
      MONTH_NAMES.forEach(function (name, i) { html += '<option value="' + (i + 1) + '"' + (viewMonth === i + 1 ? ' selected' : '') + '>' + name + '</option>'; });
      html += '</select><select aria-label="سال شمسی" data-select="year">';
      for (var y = 1300; y <= 1600; y++) html += '<option value="' + y + '"' + (viewYear === y ? ' selected' : '') + '>' + toFa(y) + '</option>';
      html += '</select></div><button type="button" data-nav="next" aria-label="ماه بعد">›</button></div><div class="jalali-picker-grid">';
      ['ش','ی','د','س','چ','پ','ج'].forEach(function (d, i) { html += '<span class="jalali-weekday" title="' + WEEKDAY_NAMES[i] + '">' + d + '</span>'; });
      for (var k = 0; k < offset; k++) html += '<span aria-hidden="true"></span>';
      for (var day = 1; day <= daysInMonth(viewYear, viewMonth); day++) {
        var isSelected = parts && parts[0] === viewYear && parts[1] === viewMonth && parts[2] === day;
        var isToday = today[0] === viewYear && today[1] === viewMonth && today[2] === day;
        html += '<button type="button" class="jalali-day' + (isSelected ? ' selected' : '') + (isToday ? ' today' : '') + '" data-day="' + day + '" aria-label="' + toFa(day) + ' ' + MONTH_NAMES[viewMonth - 1] + ' ' + toFa(viewYear) + '" aria-pressed="' + (isSelected ? 'true' : 'false') + '"' + (isToday ? ' aria-current="date"' : '') + '>' + toFa(day) + '</button>';
      }
      html += '</div><div class="jalali-picker-actions"><button type="button" data-action="today">امروز</button><button type="button" data-action="clear">پاک کردن تاریخ</button><button type="button" data-action="close">بستن</button></div>';
      panel.innerHTML = html;
    }
    button.addEventListener('click', function () {
      if (activePicker && activePicker.panel === panel) { closeDatePicker(); return; }
      closeDatePicker();
      var current = parseToGregorian(input.value);
      var date = current ? toJalali.apply(null, current.split('-').map(Number)) : appToday();
      viewYear = Math.min(1600, Math.max(1300, date[0])); viewMonth = date[1];
      activePicker = { panel: panel, button: button, row: row }; panel.hidden = false;
      button.setAttribute('aria-expanded', 'true'); render();
    });
    panel.addEventListener('change', function (e) {
      if (e.target.dataset.select === 'month') viewMonth = Number(e.target.value);
      else if (e.target.dataset.select === 'year') viewYear = Number(e.target.value);
      else return;
      render(); panel.querySelector('[data-select="' + e.target.dataset.select + '"]').focus();
    });
    panel.addEventListener('click', function (e) {
      var target = e.target.closest('button'); if (!target) return;
      if (target.dataset.day) {
        setValue(toFa(viewYear + '/' + String(viewMonth).padStart(2, '0') + '/' + String(Number(target.dataset.day)).padStart(2, '0')));
        closeDatePicker(true);
      } else if (target.dataset.nav) {
        var direction = target.dataset.nav === 'next' ? 1 : -1;
        if ((viewYear === 1300 && viewMonth === 1 && direction < 0) || (viewYear === 1600 && viewMonth === 12 && direction > 0)) return;
        viewMonth += direction;
        if (viewMonth > 12) { viewYear++; viewMonth = 1; }
        if (viewMonth < 1) { viewYear--; viewMonth = 12; }
        render(); panel.querySelector('[data-nav="' + target.dataset.nav + '"]').focus();
      } else if (target.dataset.action === 'today') {
        var now = appToday(); setValue(toFa(now[0] + '/' + String(now[1]).padStart(2, '0') + '/' + String(now[2]).padStart(2, '0'))); closeDatePicker(true);
      } else if (target.dataset.action === 'clear') { setValue(''); closeDatePicker(true); }
      else if (target.dataset.action === 'close') closeDatePicker(true);
    });
  }
  document.addEventListener('click', function (e) {
    if (activePicker && !activePicker.panel.contains(e.target) && !activePicker.row.contains(e.target)) closeDatePicker();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && activePicker) { e.preventDefault(); e.stopPropagation(); closeDatePicker(true); }
  });

  global.JalaliJS = {
    MONTH_NAMES: MONTH_NAMES,
    WEEKDAY_NAMES: WEEKDAY_NAMES,
    toFa: toFa, toEn: toEn,
    toJalali: toJalali, toGregorian: toGregorian,
    isLeap: isLeap, daysInMonth: daysInMonth,
    weekdayIndex: weekdayIndex, todayJalali: todayJalali,
    jalaliToGregorianString: jalaliToGregorianString,
    gregorianToJalaliString: gregorianToJalaliString,
    human: human, parseToGregorian: parseToGregorian,
    gregorianToJalaliInput: gregorianToJalaliInput,
    attachDatePicker: attachDatePicker, closeDatePicker: closeDatePicker
  };
})(window);
