/* Install and browser notifications are opt-in; nothing private is cached. */
(function () {
  'use strict';
  var installPrompt = null;
  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('service-worker.js').catch(function () {});
    navigator.serviceWorker.addEventListener('message', function (event) {
      if (event.data && event.data.type === 'open-notifications' && window.App) window.App.go('notifications');
    });
  }
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault(); installPrompt = event;
  });
  window.addEventListener('appinstalled', function () { installPrompt = null; });
  function message(text, type) {
    if (window.App && window.App.toast) window.App.toast(text, type || 'info', 8000);
  }
  var pushActive = false;
  var pushConfig = null;
  function requestJson(action, payload) {
    var options = { credentials:'same-origin', headers:{'Accept':'application/json'} };
    if (payload) {
      options.method = 'POST';
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = window.APP.csrf;
      options.body = JSON.stringify(Object.assign({csrf_token:window.APP.csrf}, payload));
    }
    return fetch('api.php?action=' + encodeURIComponent(action), options).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.message || 'خطای ثبت اعلان');
        return data;
      });
    });
  }
  function applicationKey(key) {
    var base64 = key.replace(/-/g, '+').replace(/_/g, '/');
    base64 += '='.repeat((4 - base64.length % 4) % 4);
    var raw = atob(base64), bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return bytes;
  }
  function enableBackgroundPush() {
    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window) || window.Notification.permission !== 'granted') return Promise.resolve(false);
    return requestJson('push_config').then(function (config) {
      pushConfig = config;
      if (!config.enabled || !config.public_key) return false;
      return navigator.serviceWorker.ready.then(function (registration) {
        return registration.pushManager.getSubscription().then(function (subscription) {
          if (subscription) return subscription;
          return registration.pushManager.subscribe({ userVisibleOnly:true, applicationServerKey:applicationKey(config.public_key) });
        });
      }).then(function (subscription) {
        return requestJson('push_subscribe', { subscription:subscription.toJSON() }).then(function () { pushActive = true; return true; });
      });
    });
  }
  function pushResult(active) {
    message(active ? 'اعلان دستگاه و پس‌زمینه برای این مرورگر ثبت شد.' : 'اعلان دستگاه فعال است؛ پوش پس‌زمینه در سرور پیکربندی نشده یا پشتیبانی نمی‌شود. برنامه باید باز بماند.');
  }
  document.addEventListener('DOMContentLoaded', function () {
    var install = document.getElementById('btnInstallApp');
    if (install) install.addEventListener('click', function (event) {
      event.preventDefault();
      if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
        message('برنامه از قبل نصب و باز شده است.'); return;
      }
      if (installPrompt) {
        var prompt = installPrompt; installPrompt = null;
        prompt.prompt(); return;
      }
      message('در مرورگر، گزینهٔ نصب برنامه یا افزودن به صفحهٔ اصلی را انتخاب کنید. در آیفون: اشتراک‌گذاری ← افزودن به صفحهٔ اصلی. نصب به HTTPS نیاز دارد.');
    });
    var button = document.getElementById('btnDesktopNotifications');
    var notice = document.getElementById('notificationPermissionNotice');
    var noticeButton = document.getElementById('btnRequestNotificationPermission');
    var title = document.getElementById('notificationPermissionTitle');
    var description = document.getElementById('notificationPermissionDescription');
    var requesting = false;

    function refreshPermissionNotice() {
      if (!notice || !noticeButton || !title || !description) return;
      var available = !!window.isSecureContext && 'Notification' in window;
      var permission = available ? window.Notification.permission : 'unsupported';
      notice.classList.toggle('hidden', permission === 'granted');
      noticeButton.disabled = requesting;
      if (permission === 'default') {
        title.textContent = '🔔 اعلان دستگاه را فعال کنید';
        description.textContent = 'برای دریافت اعلان تسک‌ها و پیام‌های جدید، اجازهٔ مرورگر را بدهید. برنامه باید باز بماند.';
        noticeButton.textContent = 'فعال‌سازی اعلان';
        noticeButton.classList.remove('hidden');
      } else if (permission === 'denied') {
        title.textContent = '🔕 اجازهٔ اعلان مسدود است';
        description.textContent = 'در تنظیمات مرورگر یا دستگاه، اعلان‌های این سایت را مجاز کنید. اعلان‌های داخل برنامه همچنان در دسترس‌اند.';
        noticeButton.textContent = 'راهنمای فعال‌سازی';
        noticeButton.classList.remove('hidden');
      } else if (permission === 'unsupported') {
        title.textContent = '🔔 اعلان دستگاه در دسترس نیست';
        description.textContent = 'اتصال امن HTTPS و پشتیبانی مرورگر را بررسی کنید. در آیفون ممکن است نیاز باشد ابتدا برنامه را به صفحهٔ اصلی اضافه و از آنجا باز کنید. اعلان‌های داخل برنامه همچنان در دسترس‌اند.';
        noticeButton.classList.add('hidden');
      }
    }

    function requestNotifications(event) {
      if (event) event.preventDefault();
      if (!window.isSecureContext || !('Notification' in window)) {
        refreshPermissionNotice();
        message('اعلان دستگاه در دسترس نیست؛ اتصال HTTPS و تنظیمات مرورگر را بررسی کنید.', 'error'); return;
      }
      if (window.Notification.permission === 'granted') {
        refreshPermissionNotice();
        enableBackgroundPush().then(pushResult).catch(function () { message('ثبت اعلان پس‌زمینه ناموفق بود؛ اعلان داخل برنامه همچنان فعال است.', 'error'); }); return;
      }
      if (window.Notification.permission === 'denied') {
        refreshPermissionNotice();
        message('در تنظیمات مرورگر یا دستگاه، مجوز اعلان این سایت را فعال کنید.', 'error'); return;
      }
      if (requesting) return;
      requesting = true;
      refreshPermissionNotice();
      try {
        // Browsers require this call directly from a user gesture.
        Promise.resolve(window.Notification.requestPermission()).then(function (permission) {
          if (permission === 'granted') return enableBackgroundPush().then(pushResult);
          message('اجازهٔ اعلان دستگاه داده نشد.', 'error');
        }).catch(function () {
          message('فعال‌سازی اعلان ممکن نشد.', 'error');
        }).then(function () {
          requesting = false;
          refreshPermissionNotice();
        });
      } catch (e) {
        requesting = false;
        refreshPermissionNotice();
        message('فعال‌سازی اعلان ممکن نشد.', 'error');
      }
    }
    if (button) button.addEventListener('click', requestNotifications);
    if (noticeButton) noticeButton.addEventListener('click', requestNotifications);
    refreshPermissionNotice();
    if (window.isSecureContext && 'Notification' in window && window.Notification.permission === 'granted') enableBackgroundPush().catch(function () {});
    var logout = document.querySelector('a[href="logout.php"]');
    if (logout) logout.addEventListener('click', function (event) {
      if (!pushActive || !('serviceWorker' in navigator)) return;
      event.preventDefault();
      var destination = logout.href;
      navigator.serviceWorker.ready.then(function (registration) {
        return registration.pushManager.getSubscription();
      }).then(function (subscription) {
        if (!subscription) return;
        return requestJson('push_unsubscribe', {endpoint:subscription.endpoint}).catch(function () {}).then(function () { return subscription.unsubscribe(); });
      }).catch(function () {}).then(function () { window.location.href = destination; });
    });
    window.addEventListener('focus', refreshPermissionNotice);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refreshPermissionNotice();
    });
    if (navigator.permissions && navigator.permissions.query && window.isSecureContext && 'Notification' in window) {
      navigator.permissions.query({name:'notifications'}).then(function (status) {
        status.addEventListener('change', refreshPermissionNotice);
      }).catch(function () {});
    }

  });
})();
