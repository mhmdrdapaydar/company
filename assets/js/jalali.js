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
    if (y >= 1950 && y <= 2200) return y + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    return null;
  }
  // تبدیل میلادی به شمسی برای نمایش در input تاریخ
  function gregorianToJalaliInput(iso) {
    if (!iso) return '';
    return gregorianToJalaliString(iso);
  }

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
    gregorianToJalaliInput: gregorianToJalaliInput
  };
})(window);
